<?php

use App\Actions\Wheel\BuildWheelCycles;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\OptionPosition;
use App\Models\StockTrade;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;

it('loads fictional demo data that adds up through the real engines', function () {
    $this->seed(DemoDataSeeder::class);

    $cycles = app(BuildWheelCycles::class)->handle(null, 'DEMOA');

    expect(Account::pluck('nickname')->sort()->values()->all())->toBe(['Demo IRA', 'Demo wheel account'])
        ->and(User::count())->toBe(0)
        ->and(OptionPosition::query()->open()->pluck('underlying')->sort()->values()->all())->toBe(['DEMOA', 'DEMOB'])
        ->and($cycles)->toHaveCount(3)
        ->and($cycles->first()->netProfit()->format())->toBe('$448.66')
        ->and($cycles->last()->isOpen())->toBeTrue();

    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()->assertSee('DEMOA');
});

it('refuses to load into a database that already holds records, leaving them untouched', function () {
    $account = Account::factory()->create();
    recordStockTrade($account, TradeSide::Buy, 'REAL', '2026-03-02', '10', '100');

    expect(fn () => $this->seed(DemoDataSeeder::class))->toThrow(RuntimeException::class, 'already holds records');

    expect(Account::sole()->is($account))->toBeTrue()
        ->and(StockTrade::count())->toBe(1);
});

it('creates no login when the default seeder runs', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(0);
});
