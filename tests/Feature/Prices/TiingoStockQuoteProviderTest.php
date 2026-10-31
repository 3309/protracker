<?php

use App\Exceptions\MarketDataException;
use App\Support\MarketData\TiingoStockQuoteProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->provider = new TiingoStockQuoteProvider('test-secret-key');
});

it('reads share-class tickers, nanosecond times and rounds the price to 4 decimal places', function () {
    Http::fake(['api.tiingo.com/iex*' => Http::response([
        ['ticker' => 'BRK-B', 'timestamp' => '2026-03-31T15:59:59.999999999-04:00', 'tngoLast' => 512.123456, 'last' => 512.1],
    ])]);

    $quote = $this->provider->quotes(['BRK.B'])['BRK.B'];

    Http::assertSent(fn (Request $request) => $request['tickers'] === 'brk-b');
    expect($quote->price->toDecimal())->toBe('512.1235')
        ->and($quote->quotedAt->utc()->format('Y-m-d H:i:s.u'))->toBe('2026-03-31 19:59:59.999999');
});

it('falls back to the IEX last trade when Tiingo has no last price', function () {
    Http::fake(['api.tiingo.com/iex*' => Http::response([
        ['ticker' => 'XYZ', 'timestamp' => '2026-03-31T10:00:00-04:00', 'tngoLast' => null, 'last' => 55.1],
    ])]);

    expect($this->provider->quotes(['XYZ'])['XYZ']->price->toDecimal())->toBe('55.1');
});

it('leaves out prices that are negative, not numbers or for tickers not asked for', function () {
    Http::fake(['api.tiingo.com/iex*' => Http::response([
        ['ticker' => 'NEG', 'timestamp' => '2026-03-31T10:00:00-04:00', 'tngoLast' => -5],
        ['ticker' => 'TXT', 'timestamp' => '2026-03-31T10:00:00-04:00', 'tngoLast' => 'n/a'],
        ['ticker' => 'OTHER', 'timestamp' => '2026-03-31T10:00:00-04:00', 'tngoLast' => 10],
        ['ticker' => 'BADTIME', 'timestamp' => 'yesterday-ish', 'tngoLast' => 10],
    ])]);

    expect($this->provider->quotes(['NEG', 'TXT', 'BADTIME']))->toBe([]);
});

it('never puts the API key in the error message', function () {
    Http::fake(['api.tiingo.com/iex*' => Http::response('Invalid token test-secret-key', 401)]);

    expect(fn () => $this->provider->quotes(['XYZ']))
        ->toThrow(fn (MarketDataException $exception) => expect($exception->getMessage())->not->toContain('test-secret-key'));
});
