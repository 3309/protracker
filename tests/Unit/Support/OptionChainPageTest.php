<?php

use App\Support\Alpaca\AlpacaResult;
use App\Support\Alpaca\OptionChainPage;

function chainContract(string $expiration, string $type, int|float $strike): array
{
    return ['symbol' => "{$expiration}-{$type}-{$strike}", 'expiration' => $expiration, 'type' => $type, 'strike' => $strike];
}

/**
 * @return list<array{0: string|null, 1: string|null, 2: string|null}> strike, call symbol and put symbol, row by row
 */
function chainRows(OptionChainPage $page, int $group = 0): array
{
    return array_map(fn (array $row): array => [$row['strike']?->toDecimal(), $row['call']['symbol'] ?? null, $row['put']['symbol'] ?? null], $page->groups[$group]['rows']);
}

it('sorts expirations by date and strikes as numbers, whatever order they arrive in', function () {
    $page = OptionChainPage::from(new AlpacaResult([
        chainContract('2026-12-18', 'call', 5),
        chainContract('2026-11-20', 'put', 1000),
        chainContract('2026-11-20', 'call', 1000),
        chainContract('2026-11-20', 'call', 95),
        chainContract('2026-11-20', 'call', 327.5),
    ], ['truncated' => false]));

    expect(array_column($page->groups, 'expiration'))->toBe(['2026-11-20', '2026-12-18'])
        ->and(array_column(chainRows($page), 0))->toBe(['95', '327.5', '1000']);
});

it('keeps the sort correct when contracts from several pages are joined out of order', function () {
    $firstPage = [chainContract('2026-11-27', 'put', 300), chainContract('2026-11-20', 'call', 400)];
    $secondPage = [chainContract('2026-11-20', 'put', 100), chainContract('2026-11-27', 'call', 200)];
    $thirdPage = [chainContract('2026-11-20', 'call', 100), chainContract('2026-11-27', 'put', 200)];

    $page = OptionChainPage::from(new AlpacaResult([...$firstPage, ...$secondPage, ...$thirdPage], ['truncated' => false]));

    expect(array_column(chainRows($page, 0), 0))->toBe(['100', '400'])
        ->and(array_column(chainRows($page, 1), 0))->toBe(['200', '300']);
});

it('lines up the call and put of each strike on one row', function () {
    $page = OptionChainPage::from(new AlpacaResult([
        chainContract('2026-11-20', 'put', 100),
        chainContract('2026-11-20', 'call', 100),
        chainContract('2026-11-20', 'put', 110),
        chainContract('2026-11-20', 'call', 120),
    ], ['truncated' => false]));

    expect(chainRows($page))->toBe([
        ['100', '2026-11-20-call-100', '2026-11-20-put-100'],
        ['110', null, '2026-11-20-put-110'],
        ['120', '2026-11-20-call-120', null],
    ]);
});

it('gives a second contract for the same side and strike its own row instead of losing it', function () {
    $page = OptionChainPage::from(new AlpacaResult([
        ['symbol' => 'AAPL261120C00100000', 'expiration' => '2026-11-20', 'type' => 'call', 'strike' => 100],
        ['symbol' => 'AAPL1261120C00100000', 'expiration' => '2026-11-20', 'type' => 'call', 'strike' => 100],
    ], ['truncated' => false]));

    expect($page->groups[0]['rows'])->toHaveCount(2)->and($page->contractCount)->toBe(2);
});

it('only says a chain is complete when the service says so', function (mixed $truncated, bool $isComplete) {
    $page = OptionChainPage::from(new AlpacaResult([chainContract('2026-11-20', 'call', 1)], $truncated === 'absent' ? [] : ['truncated' => $truncated]), canContinue: false);

    expect($page->isComplete)->toBe($isComplete);
})->with([
    'nothing left out' => [false, true],
    'cut short' => [true, false],
    'not stated' => ['absent', false],
    'not a clear answer' => ['no', false],
]);

it('holds back the last date when cut short and continues from it', function () {
    $page = OptionChainPage::from(new AlpacaResult([
        chainContract('2026-12-04', 'call', 1),
        chainContract('2026-11-20', 'call', 1),
        chainContract('2026-11-27', 'call', 1),
    ], ['truncated' => true]));

    expect(array_column($page->groups, 'expiration'))->toBe(['2026-11-20', '2026-11-27'])
        ->and($page->contractCount)->toBe(2)
        ->and($page->lastExpiration)->toBe('2026-11-27')
        ->and($page->nextFrom)->toBe('2026-12-04')
        ->and($page->isPartial)->toBeFalse()
        ->and($page->isComplete)->toBeFalse();
});

it('keeps a single cut-short date, marked partial, and moves on from the next day', function () {
    $page = OptionChainPage::from(new AlpacaResult([chainContract('2026-11-20', 'call', 1)], ['truncated' => true]));

    expect($page->contractCount)->toBe(1)
        ->and($page->nextFrom)->toBe('2026-11-21')
        ->and($page->isPartial)->toBeTrue();
});

it('offers nothing later when one date was asked for', function () {
    $page = OptionChainPage::from(new AlpacaResult([chainContract('2026-11-20', 'call', 1)], ['truncated' => true]), canContinue: false);

    expect($page->nextFrom)->toBeNull()->and($page->isPartial)->toBeTrue();
});

it('never offers an unreadable date to continue from', function () {
    $page = OptionChainPage::from(new AlpacaResult([
        chainContract("2026-11-20'); evil('", 'call', 1),
        chainContract('2026-11-20', 'call', 1),
    ], ['truncated' => true]));

    expect($page->nextFrom)->toBe('2026-11-21')->and($page->isPartial)->toBeTrue();
});

it('fills missing details from a standard option symbol', function () {
    $page = OptionChainPage::from(new AlpacaResult([['symbol' => 'AAPL261120P00300000']], ['truncated' => false]));

    expect($page->groups[0]['expiration'])->toBe('2026-11-20')
        ->and(chainRows($page))->toBe([['300', null, 'AAPL261120P00300000']]);
});

it('keeps contracts with a missing strike or date, last, without losing them', function () {
    $page = OptionChainPage::from(new AlpacaResult([
        ['symbol' => 'odd-1', 'type' => 'call'],
        ['symbol' => 'odd-2', 'type' => 'call', 'expiration' => '2026-11-20'],
        chainContract('2026-11-20', 'call', 100),
    ], ['truncated' => false]));

    expect(array_column($page->groups, 'expiration'))->toBe(['2026-11-20', null])
        ->and(array_column(chainRows($page), 0))->toBe(['100', null])
        ->and($page->contractCount)->toBe(3);
});

it('counts contracts that are neither a call nor a put rather than hiding them silently', function () {
    $page = OptionChainPage::from(new AlpacaResult([
        ['symbol' => 'odd', 'expiration' => '2026-11-20', 'strike' => 100],
        chainContract('2026-11-20', 'call', 100),
    ], ['truncated' => false]));

    expect($page->contractCount)->toBe(1)->and($page->unclassifiedCount)->toBe(1);
});

it('handles an empty chain', function () {
    $page = OptionChainPage::from(new AlpacaResult([], ['truncated' => false]));

    expect($page->groups)->toBe([])
        ->and($page->contractCount)->toBe(0)
        ->and($page->nextFrom)->toBeNull()
        ->and($page->firstExpiration)->toBeNull();
});

it('reports each expiration\'s count and data status', function () {
    $page = OptionChainPage::from(new AlpacaResult([
        [...chainContract('2026-11-20', 'call', 1), 'dataStatus' => 'delayed'],
        [...chainContract('2026-11-20', 'put', 1), 'dataStatus' => 'stale'],
        [...chainContract('2026-11-20', 'put', 2), 'dataStatus' => 'delayed'],
    ], ['truncated' => false]));

    expect($page->groups[0]['contractCount'])->toBe(3)
        ->and($page->groups[0]['statuses'])->toBe(['delayed', 'stale']);
});
