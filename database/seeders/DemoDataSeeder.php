<?php

namespace Database\Seeders;

use App\Actions\Options\SaveOptionTrade;
use App\Actions\Stocks\SaveStockTrade;
use App\Enums\AccountType;
use App\Enums\Broker;
use App\Enums\OpeningBalanceSource;
use App\Enums\OptionType;
use App\Enums\PriceInstrument;
use App\Enums\PriceSource;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\CashMovement;
use App\Models\Dividend;
use App\Models\ImportBatch;
use App\Models\OptionPosition;
use App\Models\Price;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\StockTrade;
use App\Support\DividendRate;
use App\Support\MarketCalendar;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fictional demo data for trying the app: two made-up accounts trading the made-up tickers DEMOA and DEMOB.
 * Everything is recorded through the same engines the pages use, so the dashboard, cycles and cash ledger add up.
 * Dates are placed around today so some options are still open. It refuses to run on a database that already
 * holds any records, so it can never mix with real data.
 *
 * Run with: php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    public const string NOTE = 'Fictional demo data';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ([Account::class, StockTrade::class, StockLot::class, OptionPosition::class, Dividend::class, CashMovement::class, Price::class, ImportBatch::class] as $model) {
            if ($model::query()->exists()) {
                throw new RuntimeException('Demo data was not loaded: this database already holds records. Demo data can only be loaded into an empty database.');
            }
        }

        DB::transaction(function () {
            $start = CarbonImmutable::today()->subWeeks(22)->startOfWeek();

            $this->seedWheelAccount($start);
            $this->seedCoveredCallAccount($start);

            foreach (['DEMOA' => '51.25', 'DEMOB' => '34.10'] as $ticker => $price) {
                Price::create([
                    'instrument' => PriceInstrument::Stock,
                    'ticker' => $ticker,
                    'price' => UnitPrice::fromDecimal($price),
                    'source' => PriceSource::Manual,
                    'as_of' => now(),
                    'reference_note' => self::NOTE,
                ]);
            }
        });

        $this->command?->info('Fictional demo data loaded: 2 demo accounts trading DEMOA and DEMOB.');
    }

    /**
     * A full wheel on DEMOA: a put assigned, a covered call assigned, a dividend in between, then a put that expires
     * worthless and one still open.
     */
    private function seedWheelAccount(CarbonImmutable $start): void
    {
        $account = $this->createAccount('Demo wheel account', AccountType::Taxable, '25000');

        $put = $this->sellToOpen($account, OptionType::Put, 'DEMOA', '50', $this->expiry($start->addWeeks(5)), $this->tradeDay($start), 1, '1.20');
        $this->options()->assign($put, ['event_date' => $put->expires_on->format('Y-m-d'), 'contracts' => 1], Money::zero());

        $call = $this->sellToOpen($account, OptionType::Call, 'DEMOA', '52', $this->expiry($start->addWeeks(10)), $this->tradeDay($start->addWeeks(6)), 1, '0.80');

        $dividend = Dividend::create([
            'account_id' => $account->id,
            'ticker' => 'DEMOA',
            'ex_date' => $this->tradeDay($start->addWeeks(8)->addDay()),
            'pay_date' => $this->tradeDay($start->addWeeks(10)->addDay()),
            'amount_per_share' => DividendRate::fromDecimal('0.50'),
            'reference_note' => self::NOTE,
        ]);
        $dividend->confirm($dividend->currentExpectedAmount());

        $this->options()->assign($call, ['event_date' => $call->expires_on->format('Y-m-d'), 'contracts' => 1], Money::zero());

        $expiredPut = $this->sellToOpen($account, OptionType::Put, 'DEMOA', '48', $this->expiry($start->addWeeks(15)), $this->tradeDay($start->addWeeks(11)), 1, '1.00');
        $this->options()->expire($expiredPut, ['event_date' => $expiredPut->expires_on->format('Y-m-d'), 'contracts' => 1]);

        $this->sellToOpen($account, OptionType::Put, 'DEMOA', '49', $this->expiry(CarbonImmutable::today()->addWeeks(3)), $this->tradeDay($start->addWeeks(16)), 1, '1.10');
    }

    /**
     * Covered calls on DEMOB shares: a call rolled up and out that then expires, a partial share sale, and a call
     * still open on the remaining shares.
     */
    private function seedCoveredCallAccount(CarbonImmutable $start): void
    {
        $account = $this->createAccount('Demo IRA', AccountType::Ira, '10000');

        $this->stockTrade($account, TradeSide::Buy, 'DEMOB', $this->tradeDay($start->addWeeks(1)), '200', '30.00');

        $call = $this->sellToOpen($account, OptionType::Call, 'DEMOB', '32', $this->expiry($start->addWeeks(6)), $this->tradeDay($start->addWeeks(2)), 2, '0.60');

        $rollDate = $this->tradeDay($start->addWeeks(5));
        $rolled = $this->options()->roll($call, [
            'event_date' => $rollDate,
            'settlement_date' => MarketCalendar::optionSettlementDate($rollDate),
            'contracts' => 2,
            'price_per_share' => UnitPrice::fromDecimal('1.10'),
            'fees' => $this->optionFee($account, 2),
        ], [
            'strike' => UnitPrice::fromDecimal('34'),
            'expires_on' => $this->expiry($start->addWeeks(10)),
            'opened_on' => $rollDate,
            'opening_settlement_date' => MarketCalendar::optionSettlementDate($rollDate),
            'contracts' => 2,
            'multiplier' => 100,
            'premium_per_share' => UnitPrice::fromDecimal('1.25'),
            'opening_fees' => $this->optionFee($account, 2),
        ]);
        $this->options()->expire($rolled, ['event_date' => $rolled->expires_on->format('Y-m-d'), 'contracts' => 2]);

        $this->stockTrade($account, TradeSide::Sell, 'DEMOB', $this->tradeDay($start->addWeeks(12)), '100', '33.50');

        $this->sellToOpen($account, OptionType::Call, 'DEMOB', '35', $this->expiry(CarbonImmutable::today()->addWeeks(4)), $this->tradeDay($start->addWeeks(14)), 1, '0.70');
    }

    private function createAccount(string $nickname, AccountType $type, string $openingCash): Account
    {
        $account = Account::create([
            'broker' => Broker::Other,
            'nickname' => $nickname,
            'account_type' => $type,
        ]);

        $account->opening()->create([
            'as_of_date' => Setting::current()->reporting_start_date,
            'cash_balance' => Money::fromDecimal($openingCash),
            'cash_source' => OpeningBalanceSource::Other,
            'cash_note' => self::NOTE,
        ])->markComplete();

        return $account;
    }

    private function sellToOpen(Account $account, OptionType $type, string $ticker, string $strike, CarbonImmutable $expiresOn, CarbonImmutable $openedOn, int $contracts, string $premium): OptionPosition
    {
        return $this->options()->open([
            'account_id' => $account->id,
            'underlying' => $ticker,
            'option_type' => $type,
            'strike' => UnitPrice::fromDecimal($strike),
            'expires_on' => $expiresOn,
            'opened_on' => $openedOn,
            'opening_settlement_date' => MarketCalendar::optionSettlementDate($openedOn),
            'contracts' => $contracts,
            'multiplier' => 100,
            'premium_per_share' => UnitPrice::fromDecimal($premium),
            'opening_fees' => $this->optionFee($account, $contracts),
        ]);
    }

    private function stockTrade(Account $account, TradeSide $side, string $ticker, CarbonImmutable $tradeDate, string $shares, string $price): StockTrade
    {
        return app(SaveStockTrade::class)->create([
            'account_id' => $account->id,
            'side' => $side,
            'ticker' => $ticker,
            'trade_date' => $tradeDate,
            'settlement_date' => MarketCalendar::stockSettlementDate($tradeDate),
            'shares' => Quantity::fromDecimal($shares),
            'price_per_share' => UnitPrice::fromDecimal($price),
            'fees' => Money::zero(),
        ]);
    }

    private function options(): SaveOptionTrade
    {
        return app(SaveOptionTrade::class);
    }

    private function optionFee(Account $account, int $contracts): Money
    {
        return Money::fromCents($account->option_fee_per_contract->cents * $contracts);
    }

    /**
     * The first trading day on or after the date.
     */
    private function tradeDay(CarbonImmutable $date): CarbonImmutable
    {
        while (! MarketCalendar::isTradingDay($date)) {
            $date = $date->addDay();
        }

        return $date;
    }

    /**
     * The Friday of the date's week, or the trading day before it when that Friday is a market holiday.
     */
    private function expiry(CarbonImmutable $date): CarbonImmutable
    {
        $date = $date->startOfWeek()->addDays(4);

        while (! MarketCalendar::isTradingDay($date)) {
            $date = $date->subDay();
        }

        return $date;
    }
}
