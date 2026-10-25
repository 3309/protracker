<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\AccountOpening;
use App\Models\OptionPosition;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        //
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
            'option_position' => OptionPosition::class,
            'setting' => Setting::class,
            'stock_lot' => StockLot::class,
            'user' => User::class,
        ]);
    }
}
