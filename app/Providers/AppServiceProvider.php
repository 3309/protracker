<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\AccountOpening;
use App\Models\BrokerBalance;
use App\Models\CashMovement;
use App\Models\Dividend;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Models\Price;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\StockTrade;
use App\Models\User;
use App\Support\MarketData\StockQuoteProvider;
use App\Support\MarketData\TiingoStockQuoteProvider;
use App\Support\MarketData\UnconfiguredStockQuoteProvider;
use App\Support\TradeAssistant\AnthropicTradeExtractor;
use App\Support\TradeAssistant\TradeExtractor;
use App\Support\TradeAssistant\UnconfiguredTradeExtractor;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TradeExtractor::class, function (): TradeExtractor {
            $key = (string) config('services.ai.key');

            if (config('services.ai.provider') !== 'anthropic' || $key === '') {
                return new UnconfiguredTradeExtractor;
            }

            return new AnthropicTradeExtractor(
                $key,
                (string) config('services.ai.model'),
                new GuzzleClient(['timeout' => config('services.ai.timeout'), 'connect_timeout' => 10]),
                (int) config('services.ai.max_retries'),
            );
        });

        $this->app->bind(StockQuoteProvider::class, function (): StockQuoteProvider {
            $key = (string) config('services.market_data.key');

            if (config('services.market_data.provider') !== 'tiingo' || $key === '') {
                return new UnconfiguredStockQuoteProvider;
            }

            return new TiingoStockQuoteProvider($key, (int) config('services.market_data.timeout'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : Password::min(12),
        );

        Relation::enforceMorphMap([
            'account' => Account::class,
            'account_opening' => AccountOpening::class,
            'broker_balance' => BrokerBalance::class,
            'cash_movement' => CashMovement::class,
            'dividend' => Dividend::class,
            'import_batch' => ImportBatch::class,
            'import_row' => ImportRow::class,
            'option_event' => OptionEvent::class,
            'option_position' => OptionPosition::class,
            'price' => Price::class,
            'setting' => Setting::class,
            'stock_lot' => StockLot::class,
            'stock_trade' => StockTrade::class,
            'user' => User::class,
        ]);
    }
}
