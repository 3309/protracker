<?php

use App\Exceptions\TradeAssistantException;
use App\Support\TradeAssistant\AnthropicTradeExtractor;
use App\Support\TradeAssistant\TradeContext;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    $this->requests = [];
    $this->context = new TradeContext(CarbonImmutable::parse('2026-10-27'), 'America/New_York', [7 => 'Fidelity · Taxable']);
});

/**
 * An extractor whose HTTP calls are answered, in order, by $responses
 * instead of the real API. Sent requests are collected in $this->requests.
 *
 * @param  list<Response|Throwable>  $responses
 */
function extractorAnswering(array $responses, array &$requests): AnthropicTradeExtractor
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($requests));

    return new AnthropicTradeExtractor('test-secret-key', 'claude-opus-5-5', new GuzzleClient(['handler' => $stack]), maxRetries: 0);
}

function claudeMessage(string $text, string $stopReason = 'end_turn'): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'id' => 'msg_test',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-opus-5-5',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => $stopReason,
        'stop_sequence' => null,
        'usage' => ['input_tokens' => 900, 'output_tokens' => 200],
    ]));
}

it('returns the proposal from a structured answer', function () {
    $answer = tradeAssistantOutput('buy_stock', ['account' => '7', 'symbol' => 'TSLA', 'trade_date' => '2026-06-12', 'quantity' => '100', 'price' => '400']);

    $proposal = extractorAnswering([claudeMessage(json_encode($answer))], $this->requests)
        ->extract('Buy TSLA 100 shares at $400 on June 12, 2026, in Fidelity.', $this->context);

    expect($proposal->action)->toBe('buy_stock')
        ->and($proposal->value('symbol'))->toBe('TSLA')
        ->and($proposal->value('account'))->toBe('7')
        ->and($proposal->value('price'))->toBe('400');
});

it('sends only the description, the date and the account names, with the answer format required', function () {
    extractorAnswering([claudeMessage(json_encode(tradeAssistantOutput('unclear', transactionCount: 0)))], $this->requests)
        ->extract('Buy TSLA', $this->context);

    /** @var Request $request */
    $request = $this->requests[0]['request'];
    $body = json_decode((string) $request->getBody(), true);

    expect($request->getHeaderLine('Authorization'))->toBe('Bearer test-secret-key')
        ->and($body['model'])->toBe('claude-opus-5-5')
        ->and($body['messages'])->toBe([['role' => 'user', 'content' => 'Buy TSLA']])
        ->and($body['output_config']['format']['type'])->toBe('json_schema')
        ->and($body['output_config']['format']['schema']['required'])->toContain('fields', 'action')
        ->and($body['system'])->toContain('Today is Tuesday, 2026-10-27 in the America/New_York time zone')
        ->and($body['system'])->toContain('- id 7: Fidelity · Taxable');
});

it('refuses an answer that is not valid JSON', function () {
    extractorAnswering([claudeMessage('Sure! You bought TSLA.')], $this->requests)->extract('Buy TSLA', $this->context);
})->throws(TradeAssistantException::class, 'could not be understood');

it('refuses an answer that was cut off', function () {
    extractorAnswering([claudeMessage('{"action": "buy_st', 'max_tokens')], $this->requests)->extract('Buy TSLA', $this->context);
})->throws(TradeAssistantException::class, 'could not be understood');

it('reports a declined request', function () {
    extractorAnswering([claudeMessage('', 'refusal')], $this->requests)->extract('Buy TSLA', $this->context);
})->throws(TradeAssistantException::class, 'declined');

it('reports a timeout or lost connection', function () {
    extractorAnswering([new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://api.anthropic.com/v1/messages'))], $this->requests)
        ->extract('Buy TSLA', $this->context);
})->throws(TradeAssistantException::class, 'could not be reached or took too long');

it('explains provider errors without revealing the API key', function (int $status, string $explanation) {
    $error = new Response($status, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'problem']]));

    try {
        extractorAnswering([$error], $this->requests)->extract('Buy TSLA', $this->context);
        $this->fail('No exception was thrown.');
    } catch (TradeAssistantException $exception) {
        expect($exception->getMessage())->toContain($explanation)->not->toContain('test-secret-key');
    }
})->with([
    'rejected key' => [401, 'did not accept the API key'],
    'rate limited' => [429, 'busy'],
    'server error' => [500, 'had a problem'],
]);

it('says when the API account is out of credit', function (int $status, string $type, string $message) {
    $error = new Response($status, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => $message]]));

    extractorAnswering([$error], $this->requests)->extract('Buy TSLA', $this->context);
})->throws(TradeAssistantException::class, 'out of credit')->with([
    'low balance reported as an invalid request' => [400, 'invalid_request_error', 'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.'],
    'billing error' => [402, 'billing_error', 'Payment required.'],
]);

it('treats any other invalid request as a provider problem', function () {
    $error = new Response(400, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'max_tokens: too large']]));

    extractorAnswering([$error], $this->requests)->extract('Buy TSLA', $this->context);
})->throws(TradeAssistantException::class, 'had a problem');
