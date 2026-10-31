<?php

use App\Enums\OptionType;
use App\Enums\PriceSource;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\Price;
use App\Models\StockLot;
use App\Models\User;
use App\Support\RealizedProfit;
use App\Support\ReportingPeriod;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const TIINGO_URL = 'api.tiingo.com/iex*';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 18:00:00'));
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->create();
    config(['services.market_data.provider' => 'tiingo', 'services.market_data.key' => 'test-secret-key']);
    Http::preventStrayRequests();
});

/**
 * One row of Tiingo's IEX answer.
 *
 * @return array<string, mixed>
 */
function tiingoRow(string $ticker, float|int|null $price, ?string $timestamp = '2026-03-31T13:59:58.123456789-04:00'): array
{
    return ['ticker' => $ticker, 'timestamp' => $timestamp, 'tngoLast' => $price, 'last' => $price, 'prevClose' => 50.0];
}

function refreshFromDashboard(): Testable
{
    return Livewire::test('pages::dashboard')->call('refreshStockPrices');
}

it('saves a quote for each held ticker, asking for each ticker once', function () {
    $otherAccount = Account::factory()->create();
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    recordStockTrade($otherAccount, TradeSide::Buy, 'XYZ', '2026-01-12', '10', '50');
    recordStockTrade($this->account, TradeSide::Buy, 'ABC', '2026-01-12', '10', '20');
    Http::fake([TIINGO_URL => Http::response([tiingoRow('ABC', 21.5), tiingoRow('XYZ', 55.25)])]);

    refreshFromDashboard()->assertSee('2 stock prices updated, 0 failed.');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request['tickers'] === 'abc,xyz'
        && $request->hasHeader('Authorization', 'Token test-secret-key')
        && ! str_contains($request->url(), 'test-secret-key'));

    $price = Price::where('ticker', 'XYZ')->sole();

    expect(Price::count())->toBe(2)
        ->and($price->price->format())->toBe('$55.25')
        ->and($price->source)->toBe(PriceSource::Tiingo)
        ->and($price->as_of->utc()->format('Y-m-d H:i:s'))->toBe('2026-03-31 17:59:58')
        ->and($price->retrieved_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-03-31 18:00:00');
});

it('values holdings with the fetched price: market value, unrealized P/L and its percentage', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    recordStockTrade($this->account, TradeSide::Buy, 'ABC', '2026-01-12', '10', '20');
    Http::fake([TIINGO_URL => Http::response([tiingoRow('ABC', 18), tiingoRow('XYZ', 55.25)])]);

    refreshFromDashboard()
        ->assertSeeInOrder(['ABC', '$200.00', '$20.00', '$18.00', '$180.00', '-$20.00', '-10.00%'])
        ->assertSeeInOrder(['XYZ', '$5,000.00', '$50.00', '$55.25', '$5,525.00', '+$525.00', '+10.50%', 'Tiingo (IEX)']);
});

it('reports the provider failure and keeps every saved price', function (Closure $answer, string $message) {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    Price::factory()->create(['ticker' => 'XYZ', 'price' => UnitPrice::fromDecimal('51'), 'as_of' => '2026-03-30 20:00:00']);
    Http::fake([TIINGO_URL => $answer()]);

    refreshFromDashboard()
        ->assertSee($message)
        ->assertDontSee('test-secret-key')
        ->assertSeeInOrder(['XYZ', '$51.00']);

    expect(Price::count())->toBe(1);
})->with([
    'server error' => [fn () => Http::response('Internal error', 500), 'had a problem and returned no prices'],
    'key rejected' => [fn () => Http::response(['detail' => 'Invalid token.'], 401), 'did not accept the API key'],
    'rate limited' => [fn () => Http::response('Too many requests', 429), 'request limit for your plan has been reached'],
    'timeout' => [fn () => Http::failedConnection(), 'could not be reached or took too long'],
    'malformed answer' => [fn () => Http::response('<html>Maintenance</html>', 200), 'could not be understood'],
    'answer that is not a list' => [fn () => Http::response(['detail' => 'Error'], 200), 'could not be understood'],
]);

it('lists tickers without a usable quote as failed and keeps their last known price', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    recordStockTrade($this->account, TradeSide::Buy, 'ABC', '2026-01-12', '10', '20');
    recordStockTrade($this->account, TradeSide::Buy, 'ZERO', '2026-01-12', '10', '20');
    recordStockTrade($this->account, TradeSide::Buy, 'NOTIME', '2026-01-12', '10', '20');
    Price::factory()->create(['ticker' => 'ABC', 'price' => UnitPrice::fromDecimal('19'), 'as_of' => '2026-03-30 20:00:00']);
    Http::fake([TIINGO_URL => Http::response([tiingoRow('XYZ', 55), tiingoRow('ZERO', 0), tiingoRow('NOTIME', 10, null)])]);

    refreshFromDashboard()
        ->assertSee('1 stock price updated, 3 failed. ABC: no price was returned; NOTIME: no price was returned; ZERO: no price was returned. The last known price is kept for these.')
        ->assertSeeInOrder(['ABC', '$19.00'])
        ->assertSeeInOrder(['NOTIME', 'Price unavailable']);

    expect(Price::pluck('ticker')->sort()->values()->all())->toBe(['ABC', 'XYZ']);
});

it('saves an old quote but marks it stale', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    Http::fake([TIINGO_URL => Http::response([tiingoRow('XYZ', 55, '2026-03-20T16:00:00-04:00')])]);

    refreshFromDashboard()
        ->assertSee('1 stock price updated, 0 failed.')
        ->assertSeeInOrder(['XYZ', '$55.00', 'Stale price']);
});

it('does not save a quote that is no newer than the saved price', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    Price::factory()->create(['ticker' => 'XYZ', 'price' => UnitPrice::fromDecimal('56'), 'as_of' => '2026-03-31 18:00:00']);
    Http::fake([TIINGO_URL => Http::response([tiingoRow('XYZ', 55)])]);

    refreshFromDashboard()->assertSee('0 stock prices updated, 1 already up to date, 0 failed.');

    expect(Price::count())->toBe(1);
});

it('waits before asking the provider again after a refresh', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    Http::fake([TIINGO_URL => Http::response([tiingoRow('XYZ', 55)])]);

    refreshFromDashboard();
    refreshFromDashboard()->assertSee('Wait a moment before refreshing again');

    Http::assertSentCount(1);

    $this->travel(121)->seconds();
    refreshFromDashboard();

    Http::assertSentCount(2);
});

it('does not look up a ticker that is not valid', function () {
    StockLot::factory()->for($this->account)->create(['ticker' => 'BAD TICKER', 'acquired_on' => '2026-01-12']);
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    Http::fake([TIINGO_URL => Http::response([tiingoRow('XYZ', 55)])]);

    refreshFromDashboard()->assertSee('BAD TICKER: not a valid ticker, so it was not looked up');

    Http::assertSent(fn (Request $request) => $request['tickers'] === 'xyz');
});

it('explains how to set it up and sends nothing when no provider is configured', function () {
    config(['services.market_data.provider' => null, 'services.market_data.key' => null]);
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    Http::fake();

    refreshFromDashboard()->assertSee('Automatic stock prices are not set up yet.');

    Http::assertNothingSent();
    expect(Price::count())->toBe(0);
});

it('changes only prices: no trade, lot, sale, option, cash or realized profit', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50', '0.50');
    recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-12', '40', '52', '0.25');
    $call = recordSellToOpen($this->account, OptionType::Call, 'XYZ', '60', '2026-04-17', '2026-03-02', 1, '1.20', '0.67');
    recordBuyToClose(recordSellToOpen($this->account, OptionType::Put, 'XYZ', '45', '2026-04-17', '2026-03-02', 1, '1.00', '0.67'), '2026-03-10', 1, '0.40', '0.67');
    Http::fake([TIINGO_URL => Http::response([tiingoRow('XYZ', 55)])]);

    $tables = ['stock_trades', 'stock_lots', 'stock_sale_allocations', 'option_positions', 'option_events', 'cash_movements', 'dividends', 'account_openings', 'broker_balances'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toArray()])->all();
    $realized = function () {
        $profit = RealizedProfit::for(ReportingPeriod::forYear(2026));

        return [$profit->stockTotals(), $profit->optionTotals()];
    };

    $before = $snapshot();
    $realizedBefore = $realized();

    refreshFromDashboard()->assertSee('1 stock price updated, 0 failed.');

    expect($snapshot())->toEqual($before)
        ->and($realized())->toEqual($realizedBefore)
        ->and($call->fresh()->openContracts())->toBe(1);
});
