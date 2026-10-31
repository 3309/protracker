<?php

use App\Actions\Imports\PreviewImport;
use App\Actions\Options\SaveOptionTrade;
use App\Actions\Stocks\SaveStockTrade;
use App\Enums\ImportFormatType;
use App\Enums\OptionType;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\ImportBatch;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Models\StockTrade;
use App\Support\MarketCalendar;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\TradeAssistant\TradeContext;
use App\Support\TradeAssistant\TradeExtractor;
use App\Support\TradeAssistant\TradeProposal;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Record a stock trade through the real stock engine, so its lot and the
 * FIFO sale allocations are created exactly as the app would create them.
 */
function recordStockTrade(Account $account, TradeSide $side, string $ticker, string $tradeDate, string $shares, string $price, string $fee = '0'): StockTrade
{
    return app(SaveStockTrade::class)->create([
        'account_id' => $account->id,
        'side' => $side,
        'ticker' => $ticker,
        'trade_date' => $tradeDate,
        'settlement_date' => MarketCalendar::stockSettlementDate(CarbonImmutable::parse($tradeDate)),
        'shares' => Quantity::fromDecimal($shares),
        'price_per_share' => UnitPrice::fromDecimal($price),
        'fees' => Money::fromDecimal($fee),
    ]);
}

/**
 * Read CSV text in the Protracker template format into a previewed batch.
 *
 * @param  list<string>  $lines  data lines; the template header is added
 */
function previewTemplateCsv(Account $account, array $lines, string $filename = 'activity.csv'): ImportBatch
{
    $header = 'date,action,symbol,quantity,price,fees,amount,option_type,strike,expiration,multiplier,settlement_date,ex_date,transaction_id,description';

    return app(PreviewImport::class)->handle($account, ImportFormatType::Template, implode("\n", [$header, ...$lines]), $filename, null);
}

/**
 * Read CSV text in the Fidelity activity format into a previewed batch.
 *
 * @param  list<string>  $lines  data lines; the Fidelity header is added
 */
function previewFidelityCsv(Account $account, array $lines, string $filename = 'fidelity.csv'): ImportBatch
{
    $header = 'date,symbol,instrumentType,quantity,activityType,unitPrice,currency,fee,amount,subtype,account';

    return app(PreviewImport::class)->handle($account, ImportFormatType::Fidelity, implode("\n", [$header, ...$lines]), $filename, null);
}

/**
 * Read CSV text in the Robinhood account activity format into a previewed batch.
 *
 * @param  list<string>  $lines  data lines; the Robinhood header is added
 */
function previewRobinhoodCsv(Account $account, array $lines, string $filename = 'robinhood.csv'): ImportBatch
{
    $header = '"Activity Date","Process Date","Settle Date","Instrument","Description","Trans Code","Quantity","Price","Amount"';

    return app(PreviewImport::class)->handle($account, ImportFormatType::Robinhood, implode("\n", [$header, ...$lines]), $filename, null);
}

/**
 * Record a sell-to-open option trade through the real options engine.
 */
function recordSellToOpen(Account $account, OptionType $type, string $ticker, string $strike, string $expiresOn, string $openedOn, int $contracts, string $premium, string $fee, int $multiplier = 100): OptionPosition
{
    return app(SaveOptionTrade::class)->open([
        'account_id' => $account->id,
        'underlying' => $ticker,
        'option_type' => $type,
        'strike' => UnitPrice::fromDecimal($strike),
        'expires_on' => $expiresOn,
        'opened_on' => $openedOn,
        'opening_settlement_date' => MarketCalendar::optionSettlementDate(CarbonImmutable::parse($openedOn)),
        'contracts' => $contracts,
        'multiplier' => $multiplier,
        'premium_per_share' => UnitPrice::fromDecimal($premium),
        'opening_fees' => Money::fromDecimal($fee),
    ]);
}

/**
 * Record a buy-to-open (long) option trade through the real options engine.
 */
function recordBuyToOpen(Account $account, OptionType $type, string $ticker, string $strike, string $expiresOn, string $openedOn, int $contracts, string $premium, string $fee, int $multiplier = 100): OptionPosition
{
    return app(SaveOptionTrade::class)->buyToOpen([
        'account_id' => $account->id,
        'underlying' => $ticker,
        'option_type' => $type,
        'strike' => UnitPrice::fromDecimal($strike),
        'expires_on' => $expiresOn,
        'opened_on' => $openedOn,
        'opening_settlement_date' => MarketCalendar::optionSettlementDate(CarbonImmutable::parse($openedOn)),
        'contracts' => $contracts,
        'multiplier' => $multiplier,
        'premium_per_share' => UnitPrice::fromDecimal($premium),
        'opening_fees' => Money::fromDecimal($fee),
    ]);
}

/**
 * Sell contracts of a bought option to close, through the real options engine.
 */
function recordSellToClose(OptionPosition $position, string $date, int $contracts, string $price, string $fee): OptionEvent
{
    return app(SaveOptionTrade::class)->sellToClose($position, [
        'event_date' => $date,
        'settlement_date' => MarketCalendar::optionSettlementDate(CarbonImmutable::parse($date)),
        'contracts' => $contracts,
        'price_per_share' => UnitPrice::fromDecimal($price),
        'fees' => Money::fromDecimal($fee),
    ]);
}

/**
 * Record that contracts expired worthless, through the real options engine.
 */
function recordExpiry(OptionPosition $position, string $date, int $contracts): OptionEvent
{
    return app(SaveOptionTrade::class)->expire($position, ['event_date' => $date, 'contracts' => $contracts]);
}

/**
 * Record an assignment and its stock trade at the strike, through the real engines.
 */
function recordAssignment(OptionPosition $position, string $date, int $contracts, string $stockFee = '0'): OptionEvent
{
    return app(SaveOptionTrade::class)->assign($position, ['event_date' => $date, 'contracts' => $contracts], Money::fromDecimal($stockFee));
}

/**
 * Roll contracts into a new option of the same ticker and type, through the real options engine.
 */
function recordRoll(OptionPosition $position, string $date, int $contracts, string $buyBackPrice, string $closingFee, string $newStrike, string $newExpiresOn, int $newContracts, string $newPremium, string $newOpeningFee): OptionPosition
{
    $settlementDate = MarketCalendar::optionSettlementDate(CarbonImmutable::parse($date));

    return app(SaveOptionTrade::class)->roll($position, [
        'event_date' => $date,
        'settlement_date' => $settlementDate,
        'contracts' => $contracts,
        'price_per_share' => UnitPrice::fromDecimal($buyBackPrice),
        'fees' => Money::fromDecimal($closingFee),
    ], [
        'strike' => UnitPrice::fromDecimal($newStrike),
        'expires_on' => $newExpiresOn,
        'opened_on' => $date,
        'opening_settlement_date' => $settlementDate,
        'contracts' => $newContracts,
        'multiplier' => $position->multiplier,
        'premium_per_share' => UnitPrice::fromDecimal($newPremium),
        'opening_fees' => Money::fromDecimal($newOpeningFee),
    ]);
}

/**
 * Buy back contracts of an option position through the real options engine.
 */
function recordBuyToClose(OptionPosition $position, string $date, int $contracts, string $price, string $fee): OptionEvent
{
    return app(SaveOptionTrade::class)->buyToClose($position, [
        'event_date' => $date,
        'settlement_date' => MarketCalendar::optionSettlementDate(CarbonImmutable::parse($date)),
        'contracts' => $contracts,
        'price_per_share' => UnitPrice::fromDecimal($price),
        'fees' => Money::fromDecimal($fee),
    ]);
}

/**
 * An AI Trade Assistant answer in the shape TradeProposal::schema() requires.
 * Fields given in $values are "stated" unless $statuses says otherwise; the
 * rest are null and "missing".
 *
 * @param  array<string, string|null>  $values
 * @param  array<string, string>  $statuses
 * @param  list<string>  $questions
 * @return array<string, mixed>
 */
function tradeAssistantOutput(string $action, array $values = [], array $statuses = [], array $questions = [], int $transactionCount = 1, ?string $unsupportedReason = null): array
{
    $fields = [];

    foreach (array_keys(TradeProposal::FIELDS) as $field) {
        $fields[$field] = [
            'value' => $values[$field] ?? null,
            'status' => $statuses[$field] ?? (isset($values[$field]) ? 'stated' : 'missing'),
        ];
    }

    return [
        'transaction_count' => $transactionCount,
        'action' => $action,
        'unsupported_reason' => $unsupportedReason,
        'fields' => $fields,
        'questions' => $questions,
    ];
}

/**
 * Answer every analysis with $answer (or throw it) instead of calling the
 * real AI service. Returns the stand-in, which counts its calls.
 *
 * @param  array<string, mixed>|Throwable  $answer
 */
function fakeTradeAssistant(array|Throwable $answer): object
{
    $extractor = new class($answer) implements TradeExtractor
    {
        public int $calls = 0;

        public function __construct(private array|Throwable $answer) {}

        public function extract(string $description, TradeContext $context): TradeProposal
        {
            $this->calls++;

            if ($this->answer instanceof Throwable) {
                throw $this->answer;
            }

            return TradeProposal::fromModelOutput($this->answer, $context);
        }
    };

    app()->instance(TradeExtractor::class, $extractor);

    return $extractor;
}

/**
 * @param  array<string, mixed>  $answer
 */
function analyzeTrade(array $answer, string $accountId = ''): Testable
{
    fakeTradeAssistant($answer);

    return Livewire::test('pages::trade-assistant.index')
        ->set('description', 'A trade described in plain English.')
        ->set('account_id', $accountId)
        ->call('analyze');
}

/**
 * The review panel the page shows for its proposal.
 */
function reviewPanel(Testable $page, string $panel): Testable
{
    return Livewire::test("pages::trade-assistant.{$panel}", ['proposal' => $page->get('proposal'), 'proposalKey' => $page->get('proposalKey')]);
}
