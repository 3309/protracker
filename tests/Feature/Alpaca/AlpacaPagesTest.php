<?php

use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\CashMovement;
use App\Models\ImportBatch;
use App\Models\OptionPosition;
use App\Models\Price;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\StockTrade;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Answer every account-page request; anything in $answers replaces the normal
 * answer for that path.
 *
 * @param  array<string, mixed>  $answers
 */
function fakeAlpacaAccountPage(array $answers = []): void
{
    Http::fake([...[
        '127.0.0.1:3001/api/health' => Http::response(['data' => ['status' => 'ok', 'mode' => 'paper', 'readOnly' => true, 'uptimeSeconds' => 42]]),
        '127.0.0.1:3001/api/alpaca/account' => Http::response(['data' => [
            'status' => 'ACTIVE', 'currency' => 'USD', 'cash' => '100000.126', 'buyingPower' => '400000', 'equity' => '123456.78', 'portfolioValue' => '123456.78', 'optionsBuyingPower' => '98765.43', 'optionsTradingLevel' => 3,
        ]]),
        '127.0.0.1:3001/api/alpaca/positions' => Http::response(['data' => [
            ['symbol' => 'AAPL', 'asset_class' => 'us_equity', 'qty' => '10', 'side' => 'long', 'avg_entry_price' => '320.00', 'current_price' => '330.58', 'market_value' => '3305.80', 'unrealized_pl' => '105.80'],
            ['symbol' => 'SPY261120C00750000', 'asset_class' => 'us_option', 'qty' => '2', 'side' => 'long', 'avg_entry_price' => '12.30', 'current_price' => '11', 'market_value' => '2200', 'unrealized_pl' => '-260'],
        ]]),
        '127.0.0.1:3001/api/alpaca/orders*' => Http::response(['data' => [
            ['id' => 'order-1', 'symbol' => 'MSFT', 'asset_class' => 'us_equity', 'side' => 'buy', 'type' => 'limit', 'qty' => '5', 'limit_price' => '401.5', 'status' => 'new', 'submitted_at' => '2026-10-30T14:30:00Z'],
        ]]),
    ], ...$answers]);
}

function fakeAlpacaQuote(array $data = [], array $meta = []): array
{
    return [
        'data' => ['symbol' => 'SPY', 'price' => 754.29, 'bid' => 754.2, 'ask' => 754.35, 'previousClose' => 750.1, 'dataStatus' => 'real-time', 'asOf' => '2026-11-03T15:59:30.286587431Z', 'ageSeconds' => 30, ...$data],
        'meta' => ['feed' => ['feed' => 'iex', 'label' => 'IEX', 'timeliness' => 'real-time', 'coverage' => 'One exchange'], ...$meta],
    ];
}

function fakeAlpacaChain(array $meta = []): array
{
    return [
        'data' => [['symbol' => 'SPY261120C00750000', 'type' => 'call', 'strike' => 750, 'expiration' => '2026-11-20', 'root' => 'SPY', 'bid' => 12.26, 'ask' => 12.37, 'last' => 12.3, 'impliedVolatility' => 0.18, 'greeks' => ['delta' => 0.52], 'dataStatus' => 'delayed', 'asOf' => '2026-11-03T15:59:30Z', 'ageSeconds' => 30]],
        'meta' => ['underlying' => 'SPY', 'feed' => ['feed' => 'opra', 'label' => 'OPRA'], 'truncated' => false, 'expirations' => ['2026-11-20', '2026-12-18'], ...$meta],
    ];
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config(['services.alpaca.token' => 'test-service-token']);
});

it('shows the connection, account, positions, orders, source and time', function () {
    $this->travelTo('2026-11-03 15:00:00');
    fakeAlpacaAccountPage();

    $this->get(route('alpaca.account'))
        ->assertOk()
        ->assertSeeInOrder(['Node.js service', 'Connected', 'Alpaca', 'Connected', 'ACTIVE'])
        ->assertSee('$100,000.13')
        ->assertSee('$400,000.00')
        ->assertSee('$98,765.43')
        ->assertSee('$123,456.78')
        ->assertSeeInOrder(['Stock positions', 'AAPL', '+$105.80', 'Options positions', 'SPY $750 Call · Nov 20, 2026', 'Long 2 contracts', '-$260.00', 'Open orders', 'MSFT', '$401.50'])
        ->assertSee('Source: Alpaca paper account')
        ->assertSee('Last updated: Nov 3, 2026 10:00:00 AM EST');
});

it('never puts the token in the page or the data sent to the browser', function () {
    fakeAlpacaAccountPage();

    $this->get(route('alpaca.account'))->assertOk()->assertDontSee('test-service-token', false);

    Livewire::test('pages::alpaca.account')
        ->tap(fn ($page) => expect(json_encode($page->snapshot))->not->toContain('test-service-token'));
});

it('sends only read requests from the account page and its refresh', function () {
    fakeAlpacaAccountPage();

    Livewire::test('pages::alpaca.account')->call('$refresh');

    Http::assertSentCount(8);
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
});

it('shows new data after a manual refresh', function () {
    fakeAlpacaAccountPage(['127.0.0.1:3001/api/alpaca/account' => Http::sequence()
        ->push(['data' => ['status' => 'ACTIVE', 'cash' => '111.11', 'buyingPower' => '1', 'equity' => '1']])
        ->push(['data' => ['status' => 'ACTIVE', 'cash' => '222.22', 'buyingPower' => '1', 'equity' => '1']])]);

    Livewire::test('pages::alpaca.account')
        ->assertSee('$111.11')
        ->call('$refresh')
        ->assertSee('$222.22')
        ->assertDontSee('$111.11');
});

it('shows one clear message and asks nothing else when the service is down', function () {
    Http::fake(['127.0.0.1:3001/*' => Http::failedConnection()]);

    $this->get(route('alpaca.account'))
        ->assertOk()
        ->assertSee('Alpaca data is unavailable')
        ->assertSee('could not be reached')
        ->assertSee('Not checked')
        ->assertDontSee('Alpaca reports no');

    Http::assertSentCount(1);
});

it('says the token is wrong when the service rejects it', function () {
    Http::fake(['127.0.0.1:3001/*' => Http::response(['error' => ['code' => 'unauthorized']], 401)]);

    $this->get(route('alpaca.account'))
        ->assertOk()
        ->assertSee('did not accept the API token')
        ->assertDontSee('test-service-token', false);
});

it('tells a missing token apart from a service that is down, and calls nothing', function () {
    config(['services.alpaca.token' => '']);
    Http::fake();

    $this->get(route('alpaca.account'))->assertOk()->assertSee('not set up yet');

    Http::assertNothingSent();
});

it('shows the service as connected and Alpaca as unavailable when only Alpaca fails', function () {
    fakeAlpacaAccountPage(['127.0.0.1:3001/api/alpaca/account' => Http::response(['error' => ['code' => 'alpaca_unreachable']], 503)]);

    $this->get(route('alpaca.account'))
        ->assertOk()
        ->assertSeeInOrder(['Node.js service', 'Connected', 'Alpaca', 'Unavailable'])
        ->assertSee('Account summary is unavailable')
        ->assertSee('AAPL');
});

it('keeps the other sections when one fails', function () {
    fakeAlpacaAccountPage(['127.0.0.1:3001/api/alpaca/orders*' => Http::response(['error' => ['code' => 'alpaca_error']], 502)]);

    $this->get(route('alpaca.account'))
        ->assertOk()
        ->assertSee('Open orders are unavailable')
        ->assertDontSee('Alpaca reports no open orders')
        ->assertSee('AAPL');
});

it('shows malformed positions as unavailable, not as no positions', function () {
    fakeAlpacaAccountPage(['127.0.0.1:3001/api/alpaca/positions' => Http::response(['data' => 'broken'])]);

    $this->get(route('alpaca.account'))
        ->assertOk()
        ->assertSee('Stock positions are unavailable')
        ->assertSee('Options positions are unavailable')
        ->assertSee('could not be understood')
        ->assertDontSee('Alpaca reports no stock positions');
});

it('shows empty positions and orders as empty, not as unavailable', function () {
    fakeAlpacaAccountPage([
        '127.0.0.1:3001/api/alpaca/positions' => Http::response(['data' => [], 'meta' => ['count' => 0]]),
        '127.0.0.1:3001/api/alpaca/orders*' => Http::response(['data' => [], 'meta' => ['count' => 0]]),
    ]);

    $this->get(route('alpaca.account'))
        ->assertOk()
        ->assertSee('Alpaca reports no stock positions.')
        ->assertSee('Alpaca reports no options positions.')
        ->assertSee('Alpaca reports no open orders.')
        ->assertDontSee('are unavailable');
});

it('notes positions that are neither stocks nor options', function () {
    fakeAlpacaAccountPage(['127.0.0.1:3001/api/alpaca/positions' => Http::response(['data' => [
        ['symbol' => 'BTCUSD', 'asset_class' => 'crypto', 'qty' => '1'],
    ]])]);

    $this->get(route('alpaca.account'))->assertOk()->assertSee('1 other position(s)')->assertDontSee('BTCUSD');
});

it('leaves your Fidelity and Robinhood records alone and does not mix them in', function () {
    fakeAlpacaAccountPage();
    Setting::current();
    $fidelity = Account::factory()->create(['broker' => 'fidelity']);
    $robinhood = Account::factory()->create(['broker' => 'robinhood']);
    recordStockTrade($fidelity, TradeSide::Buy, 'FIDL', '2026-01-12', '10', '50');
    recordStockTrade($robinhood, TradeSide::Buy, 'ROBN', '2026-01-12', '10', '50');

    $counts = fn (): array => array_map(fn (string $model): int => $model::query()->count(), [
        StockTrade::class, StockLot::class, OptionPosition::class, CashMovement::class, Price::class, ImportBatch::class, Account::class, AuditEntry::class,
    ]);
    $before = $counts();

    $this->get(route('alpaca.account'))->assertOk()->assertDontSee('FIDL')->assertDontSee('ROBN');

    expect($counts())->toBe($before);

    $this->get(route('positions'))->assertOk()->assertSee('FIDL')->assertSee('ROBN')->assertDontSee('AAPL');
});

it('requires logging in', function (string $route) {
    auth()->logout();
    Http::fake();

    $this->get(route($route))->assertRedirect(route('login'));

    Http::assertNothingSent();
})->with(['alpaca.account', 'alpaca.quote', 'alpaca.options']);

it('highlights the Alpaca group in the menu', function () {
    Http::fake(['127.0.0.1:3001/*' => Http::failedConnection()]);

    $html = (string) $this->get(route('alpaca.quote'))->assertOk()->getContent();

    expect($html)->toMatch('/data-nav-section="alpaca"\s+data-current/');
});

it('looks up a quote and shows its price, status, source and time', function () {
    $this->travelTo('2026-11-03 16:00:00');
    Http::fake(['127.0.0.1:3001/api/market/quote/SPY' => Http::response(fakeAlpacaQuote())]);

    Livewire::test('pages::alpaca.quote')
        ->set('symbol', ' spy ')
        ->call('lookup')
        ->assertHasNoErrors()
        ->assertSet('symbol', 'SPY')
        ->assertSee('$754.29')
        ->assertSee('Real-time')
        ->assertSee('Price as of Nov 3, 2026 10:59:30 AM EST')
        ->assertSee('Source: Alpaca, IEX feed (One exchange)')
        ->assertSee('Last updated: Nov 3, 2026 11:00:00 AM EST');
});

it('shows a missing price as a dash, not as zero', function () {
    Http::fake(['127.0.0.1:3001/api/market/quote/SPY' => Http::response(fakeAlpacaQuote(['price' => null, 'bid' => null, 'ask' => null, 'previousClose' => null, 'dataStatus' => 'unavailable', 'asOf' => null]))]);

    Livewire::test('pages::alpaca.quote')
        ->set('symbol', 'SPY')
        ->call('lookup')
        ->assertSee('—')
        ->assertDontSee('$0.00');
});

it('marks a cached quote and skips the cache on refresh', function () {
    Http::fake(['127.0.0.1:3001/api/market/quote/SPY' => Http::response(fakeAlpacaQuote())]);

    $page = Livewire::withQueryParams(['symbol' => 'SPY'])
        ->test('pages::alpaca.quote')
        ->assertDontSee('Saved copy')
        ->call('lookup')
        ->assertSee('Saved copy');

    Http::assertSentCount(1);

    $page->call('refresh')->assertDontSee('Saved copy');

    Http::assertSentCount(2);
});

it('checks the symbol on the page before asking for a quote', function (string $symbol, string $error) {
    Http::fake();

    Livewire::test('pages::alpaca.quote')
        ->set('symbol', $symbol)
        ->call('lookup')
        ->assertHasErrors(['symbol' => $error]);

    Http::assertNothingSent();
})->with([
    'blank' => ['', 'required'],
    'not one symbol' => ['../health', 'regex'],
]);

it('does not trust a symbol taken from the page address', function () {
    Http::fake();

    $this->get(route('alpaca.quote', ['symbol' => '../alpaca/orders']))
        ->assertOk()
        ->assertSee('not valid');

    Http::assertNothingSent();
});

it('says when Alpaca has no quote for a symbol', function () {
    Http::fake(['127.0.0.1:3001/api/market/quote/NOPE' => Http::response(['error' => ['code' => 'market_data_unavailable']], 404)]);

    Livewire::test('pages::alpaca.quote')
        ->set('symbol', 'NOPE')
        ->call('lookup')
        ->assertSee('No quote for NOPE')
        ->assertSee('has no data');
});

/**
 * Answer the expiration list and chain requests differently: the list is the
 * one request that reads 10 pages.
 *
 * @param  array<mixed>  $chain
 * @param  array<mixed>|null  $expirationsAnswer
 */
function fakeAlpacaOptions(array $chain, ?array $expirationsAnswer = null): void
{
    Http::fake(['127.0.0.1:3001/api/market/options/SPY*' => function (Request $request) use ($chain, $expirationsAnswer) {
        return str_contains($request->url(), 'max_pages=10')
            ? Http::response($expirationsAnswer ?? ['data' => [], 'meta' => ['expirations' => ['2026-11-20', '2026-12-18'], 'truncated' => false]])
            : Http::response($chain);
    }]);
}

function chainRequests(): int
{
    return collect(Http::recorded())->filter(fn (array $sent) => ! str_contains($sent[0]->url(), 'max_pages=10'))->count();
}

it('lists the expiration dates first and loads no chain until one is chosen', function () {
    fakeAlpacaOptions(fakeAlpacaChain());

    Livewire::test('pages::alpaca.options')
        ->set('symbol', ' spy ')
        ->call('lookup')
        ->assertHasNoErrors()
        ->assertSet('symbol', 'SPY')
        ->assertSeeInOrder(['Choose an expiration', 'Nov 20, 2026', 'Dec 18, 2026', 'All expirations, nearest first'])
        ->assertSee('Calls and puts')
        ->assertDontSee('Showing');

    expect(chainRequests())->toBe(0);
});

it('asks for an expiration before loading a chain', function () {
    fakeAlpacaOptions(fakeAlpacaChain());

    Livewire::withQueryParams(['symbol' => 'SPY'])
        ->test('pages::alpaca.options')
        ->call('loadChain')
        ->assertHasErrors(['expiration' => 'required'])
        ->assertSee('Choose an expiration.')
        ->assertSee('Load chain')
        ->assertSee('Dec 18, 2026');

    expect(chainRequests())->toBe(0);
});

it('keeps the date list on screen when another filter is not valid, and hides it for a bad symbol', function () {
    fakeAlpacaOptions(fakeAlpacaChain());

    $page = Livewire::withQueryParams(['symbol' => 'SPY', 'expiration' => '2026-11-20'])
        ->test('pages::alpaca.options')
        ->set('type', 'straddle')
        ->call('loadChain')
        ->assertHasErrors(['type'])
        ->assertSee('Load chain');

    $page->set('symbol', '../health')->call('lookup')->assertHasErrors(['symbol'])->assertDontSee('Load chain');
});

it('loads the chosen expiration and type, says how many contracts, and that nothing is left out', function () {
    fakeAlpacaOptions(fakeAlpacaChain());

    Livewire::withQueryParams(['symbol' => 'SPY'])
        ->test('pages::alpaca.options')
        ->set('type', 'call')
        ->set('expiration', '2026-11-20')
        ->call('loadChain')
        ->assertHasNoErrors()
        ->assertSee('Showing all 1 contract for Nov 20, 2026. No more are available')
        ->assertSeeInOrder(['$750.00', '$12.26', '$12.37', '$12.30', '18.00%', '0.52', 'SPY261120C00750000'])
        ->assertSee('Delayed')
        ->assertSee('Source: Alpaca, OPRA feed');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://127.0.0.1:3001/api/market/options/SPY?type=call&expiration_date=2026-11-20&limit=1000&max_pages=3');
});

it('forgets the chosen date when the symbol changes', function () {
    fakeAlpacaOptions(fakeAlpacaChain());

    Livewire::withQueryParams(['symbol' => 'SPY', 'expiration' => '2026-11-20', 'expiring-from' => '2026-11-27'])
        ->test('pages::alpaca.options')
        ->set('symbol', 'spy')
        ->assertSet('symbol', 'SPY')
        ->assertSet('expiration', '')
        ->assertSet('fromExpiration', '');
});

it('shows calls and puts side by side, strikes in number order, even when they arrive unsorted', function () {
    fakeAlpacaOptions(['data' => [
        ['symbol' => 'SPY261120P00800000', 'type' => 'put', 'strike' => 800, 'expiration' => '2026-11-20', 'bid' => 8.88],
        ['symbol' => 'SPY261120C00095000', 'type' => 'call', 'strike' => 95, 'expiration' => '2026-11-20', 'bid' => 9.55],
        ['symbol' => 'SPY261120P00095000', 'type' => 'put', 'strike' => 95, 'expiration' => '2026-11-20', 'bid' => 0.11],
        ['symbol' => 'SPY261120C00800000', 'type' => 'call', 'strike' => 800, 'expiration' => '2026-11-20', 'bid' => 0.22],
    ], 'meta' => ['truncated' => false]]);

    $html = Livewire::withQueryParams(['symbol' => 'SPY', 'expiration' => '2026-11-20'])->test('pages::alpaca.options')->html();

    preg_match('/data-chain-table.*?<\/table>/s', $html, $table);
    $table = preg_replace('/\s+/', ' ', strip_tags($table[0]));

    expect($table)->toMatch('/Calls Strike Puts .*\$9\.55 .*\$95\.00 \$0\.11 .*\$0\.22 .*\$800\.00 \$8\.88/');
});

it('shows only the side chosen, with every Greek on the wide layout', function () {
    fakeAlpacaOptions(['data' => [
        ['symbol' => 'SPY261120C00750000', 'type' => 'call', 'strike' => 750, 'expiration' => '2026-11-20', 'greeks' => ['delta' => 0.5, 'gamma' => 0.01, 'theta' => -0.28, 'vega' => 0.61, 'rho' => 0.17]],
    ], 'meta' => ['truncated' => false]]);

    $html = Livewire::withQueryParams(['symbol' => 'SPY', 'expiration' => '2026-11-20', 'type' => 'call'])->test('pages::alpaca.options')->html();

    preg_match('/data-chain-table.*?<\/table>/s', $html, $table);

    expect(strip_tags($table[0]))->toContain('Gamma', 'Theta', 'Vega', 'Rho', '-0.28', 'SPY261120C00750000')
        ->not->toContain('Puts');
});

it('renders cards for phones and a table for wide screens, with bid, ask and available Greeks on the cards', function () {
    fakeAlpacaOptions(['data' => [
        ['symbol' => 'SPY261120C00750000', 'type' => 'call', 'strike' => 750, 'expiration' => '2026-11-20', 'bid' => 12.26, 'ask' => 12.37, 'greeks' => ['delta' => 0.52, 'theta' => -0.28]],
        ['symbol' => 'SPY261120P00750000', 'type' => 'put', 'strike' => 750, 'expiration' => '2026-11-20', 'bid' => 1.11, 'ask' => 1.22],
        ['symbol' => 'SPY261120C00760000', 'type' => 'call', 'strike' => 760, 'expiration' => '2026-11-20', 'bid' => 3.33, 'ask' => 3.44],
    ], 'meta' => ['truncated' => false]]);

    $html = Livewire::withQueryParams(['symbol' => 'SPY', 'expiration' => '2026-11-20'])->test('pages::alpaca.options')->html();

    preg_match('/<ul[^>]*data-chain-cards.*?<\/ul>/s', $html, $cards);
    $text = preg_replace('/\s+/', ' ', strip_tags($cards[0]));

    expect($cards[0])->toContain('xl:hidden')
        ->and($html)->toMatch('/class="[^"]*hidden[^"]*xl:block[^"]*" data-chain-table/')
        ->and($text)->toContain('$750.00 Nov 20, 2026 Call Bid $12.26 Ask $12.37')
        ->toContain('Delta 0.52 Theta -0.28')
        ->toContain('Put Bid $1.11 Ask $1.22')
        ->toContain('Greeks not provided.')
        ->toContain('No put at this strike.');
});

it('warns whenever a chain is not known to be complete', function (array $query, array $meta, string $warning) {
    fakeAlpacaOptions(['data' => [
        ['symbol' => 'SPY261120C00750000', 'type' => 'call', 'strike' => 750, 'expiration' => '2026-11-20'],
    ], 'meta' => $meta]);

    Livewire::withQueryParams(['symbol' => 'SPY', ...$query])
        ->test('pages::alpaca.options')
        ->assertSee($warning)
        ->assertDontSee('Showing all');
})->with([
    'one date cut short' => [['expiration' => '2026-11-20'], ['truncated' => true], 'Only part of this expiration is shown'],
    'one date cut short across expirations' => [['expiration' => 'all'], ['truncated' => true], 'Only part of this expiration is shown'],
    'not stated' => [['expiration' => '2026-11-20'], [], 'This may not be the whole chain'],
]);

it('holds back a cut-off expiration, says more are available, and continues to the end', function () {
    Http::fake(['127.0.0.1:3001/api/market/options/SPY*' => function (Request $request) {
        return match (true) {
            str_contains($request->url(), 'max_pages=10') => Http::response(['data' => [], 'meta' => ['expirations' => ['2026-11-20', '2026-11-27'], 'truncated' => false]]),
            str_contains($request->url(), 'expiration_date_gte=2026-11-27') => Http::response(['data' => [['symbol' => 'SPY261127P00700000', 'type' => 'put', 'strike' => 700, 'expiration' => '2026-11-27']], 'meta' => ['truncated' => false]]),
            default => Http::response(['data' => [
                ['symbol' => 'SPY261120C00750000', 'type' => 'call', 'strike' => 750, 'expiration' => '2026-11-20', 'bid' => 7.77],
                ['symbol' => 'SPY261127C00750000', 'type' => 'call', 'strike' => 750, 'expiration' => '2026-11-27', 'bid' => 6.66],
            ], 'meta' => ['truncated' => true]]),
        };
    }]);

    $page = Livewire::withQueryParams(['symbol' => 'SPY', 'expiration' => 'all'])
        ->test('pages::alpaca.options')
        ->assertSee('Only part of the chain is shown')
        ->assertSee('Showing 1 contract expiring Nov 20, 2026. More are available')
        ->assertSee('$7.77')
        ->assertDontSee('$6.66')
        ->assertSeeHtml("\$set('fromExpiration', '2026-11-27')")
        ->assertDontSee('Back to the nearest expirations');

    $page->set('fromExpiration', '2026-11-27')
        ->assertSee('$700.00')
        ->assertDontSee('$7.77')
        ->assertSee('No later expirations are available. Earlier ones are not shown here.')
        ->assertDontSee('Show later expirations')
        ->assertDontSee('Showing all')
        ->assertSee('Back to the nearest expirations');

    $page->set('fromExpiration', '')->assertSee('$7.77');
});

it('still reaches past one expiration that is too long for a batch', function () {
    fakeAlpacaOptions(['data' => [['symbol' => 'SPY261120C00750000', 'type' => 'call', 'strike' => 750, 'expiration' => '2026-11-20']], 'meta' => ['truncated' => true]]);

    Livewire::withQueryParams(['symbol' => 'SPY', 'expiration' => 'all'])
        ->test('pages::alpaca.options')
        ->assertSeeHtml("\$set('fromExpiration', '2026-11-21')");
});

it('says when a chain uses estimated prices', function () {
    fakeAlpacaOptions(fakeAlpacaChain(['feed' => ['feed' => 'indicative', 'label' => 'Indicative'], 'fallbackFrom' => 'opra']));

    Livewire::withQueryParams(['symbol' => 'SPY', 'expiration' => '2026-11-20'])
        ->test('pages::alpaca.options')
        ->assertSee('These are estimates');
});

it('says when the list of dates may be incomplete', function () {
    fakeAlpacaOptions(fakeAlpacaChain(), ['data' => [], 'meta' => ['expirations' => ['2026-11-20'], 'truncated' => true]]);

    Livewire::withQueryParams(['symbol' => 'SPY'])
        ->test('pages::alpaca.options')
        ->assertSee('This list of dates may be incomplete');
});

it('keeps a date from the page address in the list even if the list does not have it', function () {
    fakeAlpacaOptions(fakeAlpacaChain());

    Livewire::withQueryParams(['symbol' => 'SPY', 'expiration' => '2027-01-15'])
        ->test('pages::alpaca.options')
        ->assertSeeHtml('value="2027-01-15"');
});

it('says when a symbol has no expirations', function () {
    fakeAlpacaOptions(fakeAlpacaChain(), ['data' => [], 'meta' => ['expirations' => [], 'truncated' => false]]);

    Livewire::withQueryParams(['symbol' => 'SPY'])
        ->test('pages::alpaca.options')
        ->assertSee('Alpaca lists no option expirations for SPY.')
        ->assertDontSee('Load chain');
});

it('tells no contracts apart from an unavailable chain', function (mixed $body, int $status, string $expected, string $notExpected) {
    Http::fake(['127.0.0.1:3001/api/market/options/SPY*' => function (Request $request) use ($body, $status) {
        return str_contains($request->url(), 'max_pages=10')
            ? Http::response(['data' => [], 'meta' => ['expirations' => ['2026-11-20'], 'truncated' => false]])
            : Http::response($body, $status);
    }]);

    Livewire::withQueryParams(['symbol' => 'SPY', 'expiration' => '2026-11-20'])
        ->test('pages::alpaca.options')
        ->assertSee($expected)
        ->assertDontSee($notExpected);
})->with([
    'an empty list' => [['data' => [], 'meta' => ['truncated' => false]], 200, 'Alpaca returned no contracts', 'No option chain for'],
    'nothing matches' => [['error' => ['code' => 'market_data_unavailable']], 404, 'has no data', 'Alpaca returned no contracts'],
    'the service failed' => [['error' => ['code' => 'alpaca_error']], 502, 'could not complete', 'Alpaca returned no contracts'],
    'malformed' => ['<html></html>', 200, 'could not be understood', 'Alpaca returned no contracts'],
]);

it('says when the expiration dates cannot be fetched', function () {
    Http::fake(['127.0.0.1:3001/api/market/options/SPY*' => Http::failedConnection()]);

    Livewire::withQueryParams(['symbol' => 'SPY'])
        ->test('pages::alpaca.options')
        ->assertSee('No expiration dates for SPY')
        ->assertSee('could not be reached')
        ->assertDontSee('Load chain');
});

it('shows contracts with missing figures as dashes, not zeros', function () {
    fakeAlpacaOptions(['data' => [
        ['symbol' => 'SPY261120C00750000', 'type' => 'call', 'strike' => 750, 'expiration' => '2026-11-20', 'bid' => null, 'ask' => null, 'last' => null, 'impliedVolatility' => null, 'greeks' => null],
    ], 'meta' => ['truncated' => false]]);

    Livewire::withQueryParams(['symbol' => 'SPY', 'expiration' => '2026-11-20'])
        ->test('pages::alpaca.options')
        ->assertSee('—')
        ->assertSee('Greeks not provided.')
        ->assertDontSee('$0.00');
});

it('says when contracts could not be placed as a call or a put', function () {
    fakeAlpacaOptions(['data' => [['symbol' => 'odd', 'strike' => 750, 'expiration' => '2026-11-20']], 'meta' => ['truncated' => false]]);

    Livewire::withQueryParams(['symbol' => 'SPY', 'expiration' => '2026-11-20'])
        ->test('pages::alpaca.options')
        ->assertSee('1 contract(s) did not say whether');
});

it('skips the saved copies when refreshing, and keeps the place', function () {
    fakeAlpacaOptions(fakeAlpacaChain());

    $page = Livewire::withQueryParams(['symbol' => 'SPY', 'expiration' => 'all', 'expiring-from' => '2026-11-20'])
        ->test('pages::alpaca.options')
        ->assertDontSee('Saved copy');

    Http::assertSentCount(2);

    $page->call('refresh')->assertSet('fromExpiration', '2026-11-20')->assertDontSee('Saved copy');

    Http::assertSentCount(4);
});

it('uses saved copies for the dates and chain on later visits', function () {
    fakeAlpacaOptions(fakeAlpacaChain());

    Livewire::withQueryParams(['symbol' => 'SPY', 'expiration' => '2026-11-20'])->test('pages::alpaca.options');
    Livewire::withQueryParams(['symbol' => 'SPY', 'expiration' => '2026-11-20'])->test('pages::alpaca.options')->assertSee('Saved copy');

    Http::assertSentCount(2);
});

it('starts a new lookup from no chosen expiration', function () {
    fakeAlpacaOptions(fakeAlpacaChain());

    Livewire::withQueryParams(['symbol' => 'SPY', 'expiration' => 'all', 'expiring-from' => '2026-11-20'])
        ->test('pages::alpaca.options')
        ->call('lookup')
        ->assertSet('expiration', '')
        ->assertSet('fromExpiration', '');
});

it('checks the chain filters on the page', function (string $field, string $value, string $error) {
    Http::fake();

    Livewire::test('pages::alpaca.options')
        ->set('symbol', 'SPY')
        ->set($field, $value)
        ->call('loadChain')
        ->assertHasErrors([$field => $error]);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), $value));
})->with([
    'unknown type' => ['type', 'straddle', 'in'],
    'unreadable date' => ['expiration', 'soon', 'regex'],
]);

it('checks the start date from the page address before refreshing', function () {
    fakeAlpacaOptions(fakeAlpacaChain());

    Livewire::withQueryParams(['symbol' => 'SPY', 'expiration' => 'all', 'expiring-from' => 'soon'])
        ->test('pages::alpaca.options')
        ->assertSee('not valid')
        ->call('refresh')
        ->assertHasErrors(['fromExpiration' => 'date_format']);

    expect(chainRequests())->toBe(0);
});

it('describes option orders readably, with the side and number of contracts', function () {
    fakeAlpacaAccountPage(['127.0.0.1:3001/api/alpaca/orders*' => Http::response(['data' => [
        ['id' => 'order-1', 'symbol' => 'AAPL261120P00300000', 'asset_class' => 'us_option', 'side' => 'sell', 'type' => 'limit', 'qty' => '2', 'status' => 'new'],
    ]])]);

    $this->get(route('alpaca.account'))
        ->assertOk()
        ->assertSee('AAPL $300 Put · Nov 20, 2026')
        ->assertSee('Sell 2 contracts')
        ->assertDontSee('AAPL261120P00300000');
});

it('does not guess at an incomplete option symbol or describe a stock order as contracts', function () {
    fakeAlpacaAccountPage(['127.0.0.1:3001/api/alpaca/orders*' => Http::response(['data' => [
        ['id' => 'order-1', 'symbol' => 'AAPL261120P003', 'side' => 'sell', 'qty' => '2'],
        ['id' => 'order-2', 'symbol' => 'MSFT', 'asset_class' => 'us_equity', 'side' => 'buy', 'qty' => '5'],
    ]])]);

    $this->get(route('alpaca.account'))
        ->assertOk()
        ->assertSee('AAPL261120P003')
        ->assertSee('MSFT')
        ->assertDontSee('Sell 2 contracts')
        ->assertDontSee('Buy 5 contracts');
});

it('lists the legs of a multi-leg order from what the service sends', function () {
    fakeAlpacaAccountPage(['127.0.0.1:3001/api/alpaca/orders*' => Http::response(['data' => [
        ['id' => 'order-1', 'symbol' => '', 'order_class' => 'mleg', 'side' => 'buy', 'qty' => '1', 'legs' => [
            ['symbol' => 'AAPL261120C00300000', 'side' => 'buy', 'ratio_qty' => '1'],
            ['symbol' => 'AAPL261120C00320000', 'side' => 'sell', 'ratio_qty' => '1'],
        ]],
        ['id' => 'order-2', 'symbol' => '', 'order_class' => 'mleg', 'legs' => null],
    ]])]);

    $this->get(route('alpaca.account'))
        ->assertOk()
        ->assertSee('Multi-leg')
        ->assertSee('AAPL $300 Call · Nov 20, 2026')
        ->assertSee('Buy 1 contract')
        ->assertSee('AAPL $320 Call · Nov 20, 2026')
        ->assertSee('Sell 1 contract');
});

it('renders account positions and orders as cards for phones and tables for wider screens', function () {
    fakeAlpacaAccountPage();

    $html = (string) $this->get(route('alpaca.account'))->assertOk()->getContent();

    expect(substr_count($html, 'data-cards'))->toBe(3)
        ->and(preg_match_all('/<ul class="[^"]*md:hidden[^"]*" data-cards/', $html))->toBe(3)
        ->and(preg_match_all('/<div class="card hidden overflow-x-auto p-0 md:block">/', $html))->toBe(3);
});
