<?php

use App\Exceptions\TradeAssistantException;
use App\Support\TradeAssistant\TradeContext;
use App\Support\TradeAssistant\TradeProposal;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->context = new TradeContext(CarbonImmutable::parse('2026-10-27'), 'America/New_York', [7 => 'Fidelity · Taxable']);
});

it('keeps well-formed values and cleans dollar signs and commas from amounts', function () {
    $proposal = TradeProposal::fromModelOutput(tradeAssistantOutput('sell_to_open', [
        'account' => '7', 'symbol' => 'tsla', 'option_type' => 'Put', 'strike' => '$1,250.50', 'expiration' => '2027-01-15', 'quantity' => '2', 'price' => '2.50',
    ]), $this->context);

    expect($proposal->value('symbol'))->toBe('TSLA')
        ->and($proposal->value('option_type'))->toBe('put')
        ->and($proposal->value('strike'))->toBe('1250.50')
        ->and($proposal->status('strike'))->toBe('stated')
        ->and($proposal->canBeReviewed())->toBeTrue();
});

it('drops values that are not well formed and asks about them', function (string $field, string $value) {
    $proposal = TradeProposal::fromModelOutput(tradeAssistantOutput('buy_stock', [$field => $value]), $this->context);

    expect($proposal->value($field))->toBe('')
        ->and($proposal->status($field))->toBe('ambiguous')
        ->and($proposal->questions[0])->toContain('could not be read');
})->with([
    'account that is not one of yours' => ['account', '999'],
    'ticker with spaces' => ['symbol', 'TS LA!'],
    'quantity in words' => ['quantity', 'two hundred'],
    'negative price' => ['price', '-5'],
    'impossible date' => ['trade_date', '2026-02-30'],
    'date in another format' => ['trade_date', '06/12/2026'],
    'option type that is neither put nor call' => ['option_type', 'straddle'],
]);

it('treats a field without a value as missing, whatever status the AI gave', function () {
    $proposal = TradeProposal::fromModelOutput(tradeAssistantOutput('buy_stock', statuses: ['price' => 'stated']), $this->context);

    expect($proposal->status('price'))->toBe('missing');
});

it('rejects an answer without the expected shape', function (mixed $answer) {
    TradeProposal::fromModelOutput($answer, $this->context);
})->throws(TradeAssistantException::class)->with([
    'not an object' => ['buy TSLA'],
    'unknown action' => [fn () => [...tradeAssistantOutput('buy_stock'), 'action' => 'delete_everything']],
    'missing a field' => [fn () => tap(tradeAssistantOutput('buy_stock'), function (array &$answer) {
        unset($answer['fields']['price']);
    })],
    'number instead of text' => [fn () => array_replace_recursive(tradeAssistantOutput('buy_stock'), ['fields' => ['price' => ['value' => 400.5]]])],
]);

it('only offers a review for one supported transaction', function (string $action, int $count, bool $reviewable) {
    expect(TradeProposal::fromModelOutput(tradeAssistantOutput($action, transactionCount: $count), $this->context)->canBeReviewed())->toBe($reviewable);
})->with([
    'one stock buy' => ['buy_stock', 1, true],
    'two trades' => ['buy_stock', 2, false],
    'buy to close' => ['buy_to_close', 1, true],
    'stock split' => ['not_supported', 1, false],
    'nothing recognizable' => ['unclear', 0, false],
]);
