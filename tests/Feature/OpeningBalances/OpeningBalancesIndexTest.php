<?php

use App\Enums\OpeningStatus;
use App\Models\Account;
use App\Models\AccountOpening;
use App\Models\OptionPosition;
use App\Models\StockLot;
use App\Models\User;

it('sends guests to the login page', function (string $routeName, string $model) {
    $record = $model::factory()->create();

    $this->get(route($routeName, $record))->assertRedirect(route('login'));
})->with([
    'overview' => ['opening-balances.index', Account::class],
    'account page' => ['opening-balances.show', Account::class],
    'add stock lot' => ['opening-balances.stock-lots.create', Account::class],
    'edit stock lot' => ['opening-balances.stock-lots.edit', StockLot::class],
    'add option' => ['opening-balances.options.create', Account::class],
    'edit option' => ['opening-balances.options.edit', OptionPosition::class],
]);

it('lists each active account with its opening-balance progress', function () {
    Account::factory()->create(['nickname' => 'A not started']);
    AccountOpening::factory()->for(Account::factory()->state(['nickname' => 'B in progress']))->create();
    AccountOpening::factory()->complete()->for(Account::factory()->state(['nickname' => 'C complete']))->create();
    Account::factory()->archived()->create(['nickname' => 'D archived']);

    $this->actingAs(User::factory()->create())
        ->get(route('opening-balances.index'))
        ->assertOk()
        ->assertSeeInOrder(['A not started', 'Not started', 'B in progress', 'In progress', 'C complete', 'Complete'])
        ->assertDontSee('D archived');
});

it('counts only stock lots and options that have not been voided', function () {
    $account = Account::factory()->create();
    StockLot::factory()->for($account)->count(2)->create();
    StockLot::factory()->for($account)->voided()->create();
    OptionPosition::factory()->for($account)->voided()->create();

    $account = Account::query()->withOpeningProgress()->find($account->id);

    expect($account->opening_stock_lots_count)->toBe(2)
        ->and($account->opening_option_positions_count)->toBe(0)
        ->and($account->openingStatus())->toBe(OpeningStatus::InProgress);
});

it('treats an account whose only entry was voided as not started', function () {
    $account = Account::factory()->create();
    StockLot::factory()->for($account)->voided()->create();

    expect($account->openingStatus())->toBe(OpeningStatus::NotStarted);
});

it('treats an opening record without cash as not started', function () {
    $opening = AccountOpening::factory()->withoutCash()->create();

    expect($opening->account->openingStatus())->toBe(OpeningStatus::NotStarted);
});
