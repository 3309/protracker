<?php

use App\Enums\PositionOrigin;
use App\Models\Account;
use App\Models\AccountOpening;
use App\Models\OptionPosition;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\User;
use App\Support\ReportingPeriod;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->create();
    $this->year2026 = ReportingPeriod::startingYear(Setting::current()->reporting_start_date);
});

/**
 * Example I (opening part): 100 shares bought in 2025 at $50 and $2,000 of
 * opening cash. The 2026 sale and its +$200.00 profit are tested in Phase 3.
 */
it('Example I: keeps the 2025 acquisition date and cost and does not treat them as 2026 activity', function () {
    Livewire::test('pages::opening-balances.show', ['account' => $this->account])
        ->set('cash.cash_balance', '2000')
        ->set('cash.cash_source', 'broker_statement')
        ->call('saveCash')
        ->assertHasNoErrors();

    Livewire::test('pages::opening-balances.stock-lots.create', ['account' => $this->account])
        ->set('form.ticker', 'XYZ')
        ->set('form.acquired_on', '2025-06-02')
        ->set('form.shares', '100')
        ->set('form.cost_per_share', '50')
        ->set('form.source', 'broker_statement')
        ->call('save')
        ->assertHasNoErrors();

    $lot = StockLot::sole();

    expect($lot->acquired_on->format('Y-m-d'))->toBe('2025-06-02')
        ->and($lot->cost_per_share->format())->toBe('$50.00')
        ->and($lot->costBasis()->format())->toBe('$5,000.00')
        ->and($lot->origin)->toBe(PositionOrigin::OpeningBalance)
        ->and($this->year2026->contains($lot->acquired_on))->toBeFalse()
        ->and($this->year2026->applyTo(StockLot::query(), 'acquired_on')->exists())->toBeFalse();

    $opening = AccountOpening::sole();

    expect($opening->cash_balance->format())->toBe('$2,000.00')
        ->and($opening->as_of_date->format('Y-m-d'))->toBe('2026-01-01');
});

it('keeps the original opening details of an option opened in 2025 that is still open in 2026', function () {
    Livewire::test('pages::opening-balances.options.create', ['account' => $this->account])
        ->set('form.underlying', 'XYZ')
        ->set('form.option_type', 'put')
        ->set('form.strike', '50')
        ->set('form.opened_on', '2025-12-05')
        ->set('form.expires_on', '2026-01-16')
        ->set('form.contracts', '1')
        ->set('form.premium_per_share', '1.20')
        ->set('form.opening_fees', '0.67')
        ->set('form.source', 'broker_statement')
        ->call('save')
        ->assertHasNoErrors();

    $option = OptionPosition::sole();

    expect($option->opened_on->format('Y-m-d'))->toBe('2025-12-05')
        ->and($option->premiumReceived()->format())->toBe('$120.00')
        ->and($option->opening_fees->format())->toBe('$0.67')
        ->and($this->year2026->applyTo(OptionPosition::query(), 'opened_on')->exists())->toBeFalse()
        ->and($this->year2026->contains($option->expires_on))->toBeTrue();
});
