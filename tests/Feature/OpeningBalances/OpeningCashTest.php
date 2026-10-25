<?php

use App\Enums\AuditEvent;
use App\Enums\OpeningBalanceSource;
use App\Models\Account;
use App\Models\AccountOpening;
use App\Models\StockLot;
use App\Models\User;
use App\Support\Money;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->account = Account::factory()->create();
});

it('labels the page as opening balances as of the reporting start date', function () {
    $this->get(route('opening-balances.show', $this->account))
        ->assertOk()
        ->assertSee('Opening balance — as of January 1, 2026')
        ->assertSee('Not started')
        ->assertSee('not a deposit or income');
});

it('saves the starting cash as of the reporting start date', function () {
    Livewire::test('pages::opening-balances.show', ['account' => $this->account])
        ->set('cash.cash_balance', '2000')
        ->set('cash.cash_source', 'broker_statement')
        ->set('cash.cash_note', 'Dec 2025 statement')
        ->call('saveCash')
        ->assertHasNoErrors()
        ->assertSee('Starting cash saved.')
        ->assertSee('In progress');

    $opening = AccountOpening::sole();

    expect($opening->account_id)->toBe($this->account->id)
        ->and($opening->as_of_date->format('Y-m-d'))->toBe('2026-01-01')
        ->and($opening->cash_balance->cents)->toBe(200_000)
        ->and($opening->unsettled_cash)->toBeNull()
        ->and($opening->cash_source)->toBe(OpeningBalanceSource::BrokerStatement)
        ->and($opening->cash_note)->toBe('Dec 2025 statement')
        ->and($opening->auditEntries()->sole())
        ->event->toBe(AuditEvent::Created)
        ->user_id->toBe($this->user->id);
});

it('accepts negative cash and an unsettled amount', function () {
    Livewire::test('pages::opening-balances.show', ['account' => $this->account])
        ->set('cash.cash_balance', '-150.25')
        ->set('cash.unsettled_cash', '40.10')
        ->set('cash.cash_source', 'manual_entry')
        ->call('saveCash')
        ->assertHasNoErrors();

    expect(AccountOpening::sole())
        ->cash_balance->cents->toBe(-15_025)
        ->unsettled_cash->cents->toBe(4_010);
});

it('requires the cash amount and its source', function () {
    Livewire::test('pages::opening-balances.show', ['account' => $this->account])
        ->call('saveCash')
        ->assertHasErrors(['cash.cash_balance' => 'required', 'cash.cash_source' => 'required']);

    expect(AccountOpening::count())->toBe(0);
});

it('rejects cash amounts with fractions of a cent or text', function (string $amount) {
    Livewire::test('pages::opening-balances.show', ['account' => $this->account])
        ->set('cash.cash_balance', $amount)
        ->set('cash.cash_source', 'manual_entry')
        ->call('saveCash')
        ->assertHasErrors(['cash.cash_balance' => 'regex']);
})->with(['fraction of a cent' => '10.005', 'text' => 'lots']);

it('requires a reason to correct the starting cash and records the change', function () {
    AccountOpening::factory()->for($this->account)->create(['cash_balance' => Money::fromCents(200_000)]);

    $page = Livewire::test('pages::opening-balances.show', ['account' => $this->account])
        ->assertSet('cash.cash_balance', '2000.00')
        ->set('cash.cash_balance', '2100')
        ->call('saveCash')
        ->assertHasErrors(['cash.reason' => 'required']);

    expect(AccountOpening::sole()->cash_balance->cents)->toBe(200_000);

    $page->set('cash.reason', 'Missed a pending deposit')
        ->call('saveCash')
        ->assertHasNoErrors()
        ->assertSee('Cash Balance: $2,000.00 → $2,100.00')
        ->assertSee('Reason: Missed a pending deposit');

    $entry = AccountOpening::sole()->auditEntries()->where('event', AuditEvent::Updated)->sole();

    expect($entry->old_values)->toBe(['cash_balance' => 200_000])
        ->and($entry->new_values)->toBe(['cash_balance' => 210_000]);
});

it('requires the starting cash before the account can be marked complete', function () {
    Livewire::test('pages::opening-balances.show', ['account' => $this->account])
        ->call('markComplete')
        ->assertHasErrors('completion')
        ->assertSee('Enter the starting cash');

    expect(AccountOpening::count())->toBe(0);
});

it('marks the account complete and can reopen it', function () {
    AccountOpening::factory()->for($this->account)->create();

    $page = Livewire::test('pages::opening-balances.show', ['account' => $this->account])
        ->call('markComplete')
        ->assertHasNoErrors()
        ->assertSee('Opening balances marked complete.');

    expect(AccountOpening::sole()->isComplete())->toBeTrue();

    $page->call('markInProgress')->assertSee('In progress');

    expect(AccountOpening::sole()->isComplete())->toBeFalse();
});

it('shows only the entries belonging to this account', function () {
    StockLot::factory()->for($this->account)->create(['ticker' => 'MINE']);
    StockLot::factory()->create(['ticker' => 'OTHER']);

    $this->get(route('opening-balances.show', $this->account))
        ->assertSee('MINE')
        ->assertDontSee('OTHER');
});

it('returns 404 for an account that does not exist', function () {
    $this->get('/opening-balances/999')->assertNotFound();
});
