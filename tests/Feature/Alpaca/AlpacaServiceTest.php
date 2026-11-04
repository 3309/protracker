<?php

use App\Exceptions\AlpacaServiceException;
use App\Services\AlpacaService;
use App\Support\Alpaca\AlpacaResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function alpacaService(string $token = 'test-service-token', string $url = 'http://127.0.0.1:3001', int $quoteCacheSeconds = 15): AlpacaService
{
    return new AlpacaService($url, $token, quoteCacheSeconds: $quoteCacheSeconds);
}

function alpacaHealthBody(): array
{
    return ['data' => ['status' => 'ok', 'mode' => 'paper', 'readOnly' => true, 'uptimeSeconds' => 42]];
}

function alpacaQuoteBody(float $price = 754.29): array
{
    return [
        'data' => ['symbol' => 'SPY', 'price' => $price, 'bid' => 754.2, 'ask' => 754.35, 'previousClose' => 750.1, 'dataStatus' => 'real-time', 'asOf' => '2026-11-03T15:59:30.286587431Z', 'ageSeconds' => 30],
        'meta' => ['feed' => ['feed' => 'iex', 'label' => 'IEX']],
    ];
}

it('sends the token as a bearer header on a GET request and returns the data', function () {
    Http::fake(['127.0.0.1:3001/api/health' => Http::response(alpacaHealthBody())]);

    $result = alpacaService()->health();

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'http://127.0.0.1:3001/api/health'
        && $request->header('Authorization') === ['Bearer test-service-token']);

    expect($result->data['mode'])->toBe('paper')
        ->and($result->fetchedAt)->not->toBeNull()
        ->and($result->fromCache)->toBeFalse();
});

it('uses the documented path for each endpoint', function (Closure $call, string $url, array $body) {
    Http::fake([$url => Http::response($body)]);

    $call(alpacaService());

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'http://'.$url);
})->with([
    'account' => [fn (AlpacaService $service) => $service->account(), '127.0.0.1:3001/api/alpaca/account', ['data' => ['cash' => '1', 'buyingPower' => '2', 'equity' => '3']]],
    'positions' => [fn (AlpacaService $service) => $service->positions(), '127.0.0.1:3001/api/alpaca/positions', ['data' => []]],
    'open orders' => [fn (AlpacaService $service) => $service->openOrders(), '127.0.0.1:3001/api/alpaca/orders?status=open&limit=500', ['data' => []]],
    'quote' => [fn (AlpacaService $service) => $service->quote('spy'), '127.0.0.1:3001/api/market/quote/SPY', alpacaQuoteBody()],
    'option chain' => [fn (AlpacaService $service) => $service->optionChain('spy', 'call', '2026-11-20'), '127.0.0.1:3001/api/market/options/SPY?type=call&expiration_date=2026-11-20&limit=1000&max_pages=3', ['data' => []]],
    'option chain across expirations' => [fn (AlpacaService $service) => $service->optionChain('spy'), '127.0.0.1:3001/api/market/options/SPY?limit=500&max_pages=1', ['data' => []]],
    'option expirations' => [fn (AlpacaService $service) => $service->optionExpirations('spy'), '127.0.0.1:3001/api/market/options/SPY?limit=1000&max_pages=10', ['data' => [], 'meta' => ['expirations' => [], 'truncated' => false]]],
]);

it('reports a rejected token and never repeats the token or the response', function () {
    Http::fake(['127.0.0.1:3001/*' => Http::response(['error' => ['code' => 'unauthorized', 'message' => 'bad test-service-token']], 401)]);

    expect(fn () => alpacaService()->health())->toThrow(function (AlpacaServiceException $exception) {
        expect($exception->getMessage())->toContain('did not accept the API token')
            ->not->toContain('test-service-token');
    });
});

it('reports an unreachable service when the connection fails or times out', function () {
    Http::fake(['127.0.0.1:3001/*' => Http::failedConnection()]);

    expect(fn () => alpacaService()->health())
        ->toThrow(AlpacaServiceException::class, 'could not be reached or took too long');
});

it('writes nothing to the log when a request fails', function () {
    Log::spy();
    Http::fake(['127.0.0.1:3001/*' => Http::response('test-service-token', 401)]);

    expect(fn () => alpacaService()->health())->toThrow(AlpacaServiceException::class);

    Log::shouldNotHaveReceived('log');
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('info');
});

it('refuses an answer in the wrong shape', function (Closure $call, mixed $body) {
    Http::fake(['127.0.0.1:3001/*' => Http::response($body)]);

    expect(fn () => $call(alpacaService()))->toThrow(AlpacaServiceException::class, 'could not be understood');
})->with([
    'a web page instead of JSON' => [fn (AlpacaService $service) => $service->positions(), '<html>Hello</html>'],
    'no data key' => [fn (AlpacaService $service) => $service->positions(), ['positions' => []]],
    'data is not a list or object' => [fn (AlpacaService $service) => $service->positions(), ['data' => 'ok']],
    'positions keyed instead of a list' => [fn (AlpacaService $service) => $service->positions(), ['data' => ['a' => ['symbol' => 'SPY']]]],
    'a position without a symbol' => [fn (AlpacaService $service) => $service->positions(), ['data' => [['qty' => '1']]]],
    'an order without an id' => [fn (AlpacaService $service) => $service->openOrders(), ['data' => [['symbol' => 'SPY']]]],
    'a contract that is not an object' => [fn (AlpacaService $service) => $service->optionChain('SPY'), ['data' => ['SPY261120C00750000']]],
    'an account without a cash amount' => [fn (AlpacaService $service) => $service->account(), ['data' => ['buyingPower' => '2', 'equity' => '3']]],
    'an account with text as money' => [fn (AlpacaService $service) => $service->account(), ['data' => ['cash' => 'lots', 'buyingPower' => '2', 'equity' => '3']]],
    'a quote without a data status' => [fn (AlpacaService $service) => $service->quote('SPY'), ['data' => ['symbol' => 'SPY']]],
    'health without a status' => [fn (AlpacaService $service) => $service->health(), ['data' => ['mode' => 'paper', 'readOnly' => true]]],
]);

it('accepts an empty list of positions, orders or contracts as a real answer', function (Closure $call) {
    Http::fake(['127.0.0.1:3001/*' => Http::response(['data' => [], 'meta' => ['count' => 0]])]);

    $result = $call(alpacaService());

    expect($result->isAvailable())->toBeTrue()->and($result->isEmpty())->toBeTrue();
})->with([
    'positions' => [fn (AlpacaService $service) => $service->positions()],
    'orders' => [fn (AlpacaService $service) => $service->openOrders()],
    'contracts' => [fn (AlpacaService $service) => $service->optionChain('SPY')],
]);

it('explains each failure the service can report', function (int $status, string $code, string $expected) {
    Http::fake(['127.0.0.1:3001/*' => Http::response(['error' => ['code' => $code, 'message' => 'Fixed text.']], $status)]);

    expect(fn () => alpacaService()->positions())->toThrow(AlpacaServiceException::class, $expected);
})->with([
    'Alpaca rejected the keys, not the token' => [502, 'alpaca_auth_failed', 'Alpaca rejected its keys'],
    'permission denied' => [502, 'alpaca_permission_denied', 'denied access'],
    'Alpaca problem' => [502, 'alpaca_error', 'Alpaca could not complete'],
    'Alpaca unreachable' => [503, 'alpaca_unreachable', 'could not reach Alpaca'],
    'no data' => [404, 'market_data_unavailable', 'has no data'],
    'data plan' => [403, 'market_data_restricted', 'plan does not include'],
    'invalid request' => [400, 'validation_failed', 'not valid'],
    'unknown path, such as another program on the port' => [404, 'not_found', 'had a problem'],
    'server error' => [500, 'internal_error', 'had a problem'],
]);

it('says how long to wait when the request limit is reached', function () {
    Http::fake(['127.0.0.1:3001/*' => Http::response(['error' => ['code' => 'rate_limited']], 429, ['Retry-After' => '37'])]);

    expect(fn () => alpacaService()->positions())->toThrow(AlpacaServiceException::class, 'Wait 37 seconds');
});

it('refuses to treat a live or writable service as the paper one', function (array $health) {
    Http::fake(['127.0.0.1:3001/api/health' => Http::response(['data' => $health])]);

    expect(fn () => alpacaService()->health())->toThrow(AlpacaServiceException::class, 'paper account');
})->with([
    'live' => [['status' => 'ok', 'mode' => 'live', 'readOnly' => true]],
    'writable' => [['status' => 'ok', 'mode' => 'paper', 'readOnly' => false]],
]);

it('makes no request without a token', function () {
    Http::fake();

    expect(fn () => alpacaService(token: '')->health())->toThrow(AlpacaServiceException::class, 'not set up');

    Http::assertNothingSent();
});

it('never sends the token off this computer', function (string $url, bool $allowed) {
    Http::fake(['*' => Http::response(alpacaHealthBody())]);

    if ($allowed) {
        expect(alpacaService(url: $url)->health()->isAvailable())->toBeTrue();
        Http::assertSentCount(1);

        return;
    }

    expect(fn () => alpacaService(url: $url)->health())->toThrow(AlpacaServiceException::class, 'must point to this computer');

    Http::assertNothingSent();
})->with([
    'loopback address' => ['http://127.0.0.1:3100', true],
    'localhost' => ['http://localhost:3001', true],
    'a remote host' => ['https://example.com', false],
    'a local-looking subdomain' => ['http://127.0.0.1.example.com', false],
    'loopback as a username' => ['http://127.0.0.1@example.com', false],
    'a local network address' => ['http://192.168.1.20:3001', false],
    'not an address' => ['nonsense', false],
]);

it('refuses a symbol or filter that could change the request address', function (Closure $call) {
    Http::fake();

    expect(fn () => $call(alpacaService()))->toThrow(AlpacaServiceException::class, 'not valid');

    Http::assertNothingSent();
})->with([
    'path traversal' => [fn (AlpacaService $service) => $service->quote('../alpaca/orders')],
    'a query string' => [fn (AlpacaService $service) => $service->quote('SPY?feed=sip')],
    'blank' => [fn (AlpacaService $service) => $service->quote('  ')],
    'option chain path traversal' => [fn (AlpacaService $service) => $service->optionChain('../health')],
    'an unknown option type' => [fn (AlpacaService $service) => $service->optionChain('SPY', 'straddle')],
    'an unreadable expiration' => [fn (AlpacaService $service) => $service->optionChain('SPY', '', 'soon')],
    'an unreadable start date' => [fn (AlpacaService $service) => $service->optionChain('SPY', '', '', 'x&limit=1')],
]);

it('reuses a quote until the cache time passes, then fetches it again', function () {
    $this->travelTo('2026-11-03 15:00:00');
    Http::fake(['127.0.0.1:3001/api/market/quote/SPY' => Http::sequence()->push(alpacaQuoteBody(754.29))->push(alpacaQuoteBody(755.00))]);

    $first = alpacaService()->quote('SPY');
    $this->travel(10)->seconds();
    $second = alpacaService()->quote('spy');

    expect($first->fromCache)->toBeFalse()
        ->and($second->fromCache)->toBeTrue()
        ->and($second->data['price'])->toBe(754.29)
        ->and($second->fetchedAt->equalTo($first->fetchedAt))->toBeTrue();
    Http::assertSentCount(1);

    $this->travel(6)->seconds();

    expect(alpacaService()->quote('SPY')->data['price'])->toEqual(755);
    Http::assertSentCount(2);
});

it('fetches a fresh quote after a manual refresh', function () {
    Http::fake(['127.0.0.1:3001/api/market/quote/SPY' => Http::sequence()->push(alpacaQuoteBody(754.29))->push(alpacaQuoteBody(755.00))]);

    alpacaService()->quote('SPY');
    alpacaService()->forgetQuote('spy');

    expect(alpacaService()->quote('SPY')->fromCache)->toBeFalse();
    Http::assertSentCount(2);
});

it('does not cache when the cache time is zero', function () {
    Http::fake(['127.0.0.1:3001/api/market/quote/SPY' => Http::response(alpacaQuoteBody())]);

    alpacaService(quoteCacheSeconds: 0)->quote('SPY');
    alpacaService(quoteCacheSeconds: 0)->quote('SPY');

    Http::assertSentCount(2);
});

it('does not cache a failure', function () {
    Http::fake(['127.0.0.1:3001/api/market/quote/SPY' => Http::sequence()
        ->push(['error' => ['code' => 'alpaca_unreachable']], 503)
        ->push(alpacaQuoteBody())]);

    expect(AlpacaResult::attempt(fn () => alpacaService()->quote('SPY'))->isAvailable())->toBeFalse()
        ->and(alpacaService()->quote('SPY')->fromCache)->toBeFalse();
});

it('fetches again when what was saved in the cache is unusable', function () {
    Cache::put('alpaca:quote:SPY', ['data' => 'broken'], 60);
    Http::fake(['127.0.0.1:3001/api/market/quote/SPY' => Http::response(alpacaQuoteBody())]);

    expect(alpacaService()->quote('SPY')->fromCache)->toBeFalse();
    Http::assertSentCount(1);
});

it('keeps option chains with different filters apart in the cache', function () {
    Http::fake(['127.0.0.1:3001/api/market/options/SPY*' => Http::response(['data' => []])]);

    alpacaService()->optionChain('SPY', 'call');
    alpacaService()->optionChain('SPY', 'put');
    alpacaService()->optionChain('SPY', 'call');
    alpacaService()->optionChain('SPY', 'call', '', '2026-12-18');

    Http::assertSentCount(3);
});

it('asks for later expirations with the documented filter, unless one expiration is chosen', function () {
    Http::fake(['127.0.0.1:3001/api/market/options/SPY*' => Http::response(['data' => []])]);

    alpacaService()->optionChain('SPY', '', '', '2026-12-18');
    alpacaService()->optionChain('SPY', '', '2026-11-20', '2026-12-18');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://127.0.0.1:3001/api/market/options/SPY?expiration_date_gte=2026-12-18&limit=500&max_pages=1');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://127.0.0.1:3001/api/market/options/SPY?expiration_date=2026-11-20&limit=1000&max_pages=3');
});

it('lists expiration dates in order and keeps only the dates', function () {
    Http::fake(['127.0.0.1:3001/api/market/options/SPY*' => Http::response([
        'data' => [['symbol' => 'SPY261120C00750000', 'strike' => 750]],
        'meta' => ['expirations' => ['2026-12-18', '2026-11-20'], 'truncated' => false],
    ])]);

    $result = alpacaService()->optionExpirations('spy');

    expect($result->data)->toBe(['2026-11-20', '2026-12-18'])
        ->and($result->meta)->toBe(['truncated' => false]);
});

it('passes on that the list of dates may be incomplete', function () {
    Http::fake(['127.0.0.1:3001/api/market/options/SPY*' => Http::response(['data' => [], 'meta' => ['expirations' => ['2026-11-20'], 'truncated' => true]])]);

    expect(alpacaService()->optionExpirations('SPY')->meta['truncated'])->toBeTrue();
});

it('refuses a list of dates in the wrong shape', function (mixed $expirations) {
    Http::fake(['127.0.0.1:3001/api/market/options/SPY*' => Http::response(['data' => [], 'meta' => ['expirations' => $expirations]])]);

    expect(fn () => alpacaService()->optionExpirations('SPY'))->toThrow(AlpacaServiceException::class, 'could not be understood');
})->with([
    'missing' => [null],
    'not a list' => [['a' => '2026-11-20']],
    'not dates' => [['soon']],
    'unsafe text' => [["2026-11-20'); evil('"]],
]);

it('reuses the list of dates for an hour, until refreshed', function () {
    $this->travelTo('2026-11-03 15:00:00');
    Http::fake(['127.0.0.1:3001/api/market/options/SPY*' => Http::response(['data' => [], 'meta' => ['expirations' => ['2026-11-20'], 'truncated' => false]])]);

    alpacaService()->optionExpirations('SPY');
    $this->travel(59)->minutes();

    expect(alpacaService()->optionExpirations('SPY')->fromCache)->toBeTrue();
    Http::assertSentCount(1);

    $this->travel(2)->minutes();
    alpacaService()->optionExpirations('SPY');
    Http::assertSentCount(2);

    alpacaService()->forgetOptionExpirations('spy');
    expect(alpacaService()->optionExpirations('SPY')->fromCache)->toBeFalse();
    Http::assertSentCount(3);
});

it('reuses a chain until its cache time passes', function () {
    $this->travelTo('2026-11-03 15:00:00');
    Http::fake(['127.0.0.1:3001/api/market/options/SPY*' => Http::response(['data' => []])]);

    alpacaService()->optionChain('SPY', 'call', '2026-11-20');
    $this->travel(59)->seconds();

    expect(alpacaService()->optionChain('SPY', 'call', '2026-11-20')->fromCache)->toBeTrue();

    $this->travel(2)->seconds();

    expect(alpacaService()->optionChain('SPY', 'call', '2026-11-20')->fromCache)->toBeFalse();
    Http::assertSentCount(2);
});
