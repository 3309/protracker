<?php

use App\Enums\CashMovementType;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\AccountOpening;
use App\Models\BrokerBalance;
use App\Models\CashMovement;
use App\Models\User;
use App\Support\Money;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->create(['nickname' => 'Main']);
    AccountOpening::factory()->for($this->account)->create(['cash_balance' => Money::fromDecimal('2000')]);
});

describe('ledger', function () {
    it('lists each movement with its source and a running balance', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '10', '50', '0.65');

        Livewire::withQueryParams(['year' => '2026', 'account' => (string) $this->account->id])
            ->test('pages::cash.index')
            ->assertSeeInOrder(['Balance brought forward', '$0.00'])
            ->assertSeeInOrder(['Jan 1, 2026', 'Opening balance', 'Opening cash', '$2,000.00', '$2,000.00'])
            ->assertSeeInOrder(['Jan 12, 2026', 'Jan 13, 2026', 'Stock buy', 'Bought 10 XYZ at $50.00', '-$500.00', '-$0.65', '-$500.65', '$1,499.35']);
    });

    it('carries the balance into a later period', function () {
        Livewire::withQueryParams(['year' => 'custom', 'from' => '2026-02-01', 'to' => '2026-02-28', 'account' => (string) $this->account->id])
            ->test('pages::cash.index')
            ->assertSeeInOrder(['Balance brought forward', '$2,000.00'])
            ->assertSee('No cash movements in this period.');
    });

    it('shows total, settled and unsettled cash', function () {
        $this->travelTo(now()->setDate(2026, 7, 2)->setTime(18, 0));
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-07-02', '10', '50');

        Livewire::withQueryParams(['year' => '2026', 'account' => (string) $this->account->id])
            ->test('pages::cash.index')
            ->assertSeeInOrder(['Total cash · Jul 2, 2026', '$1,500.00', 'Settled cash', '$2,000.00', 'Unsettled', '-$500.00']);
    });
});

describe('standalone movements', function () {
    it('records a deposit, which adds cash', function () {
        Livewire::test('pages::cash.movements.create')
            ->set('form.account_id', (string) $this->account->id)
            ->set('form.movement_type', 'deposit')
            ->set('form.movement_date', '2026-02-02')
            ->set('form.amount', '1000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('cash.index'));

        expect(CashMovement::sole())
            ->settlement_date->format('Y-m-d')->toBe('2026-02-02')
            ->signedAmount()->format()->toBe('$1,000.00');
    });

    it('rejects invalid values', function (string $field, string $value) {
        Livewire::test('pages::cash.movements.create')
            ->set('form.account_id', (string) $this->account->id)
            ->set('form.movement_type', 'withdrawal')
            ->set('form.movement_date', '2026-02-02')
            ->set('form.amount', '100')
            ->set($field, $value)
            ->call('save')
            ->assertHasErrors($field);
    })->with([
        'before the reporting start date' => ['form.movement_date', '2025-12-31'],
        'zero amount' => ['form.amount', '0'],
        'negative amount' => ['form.amount', '-100'],
        'unknown type' => ['form.movement_type', 'gift'],
        'settles before the date' => ['form.settlement_date', '2026-02-01'],
    ]);

    it('requires a reason to correct or void a movement', function () {
        $movement = CashMovement::factory()->for($this->account)->create(['movement_type' => CashMovementType::Interest, 'amount' => Money::fromDecimal('1.23')]);

        Livewire::test('pages::cash.movements.edit', ['cashMovement' => $movement])
            ->set('form.amount', '1.32')
            ->call('save')
            ->assertHasErrors(['form.reason' => 'required'])
            ->set('form.reason', 'Typo')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Amount: $1.23 → $1.32')
            ->set('form.reason', 'Duplicate')
            ->call('void')
            ->assertSee('Cash movement voided.');

        expect($movement->fresh()->isVoided())->toBeTrue();
    });
});

describe('reconciliation', function () {
    it('shows a match when the broker balance equals the calculated cash', function () {
        Livewire::withQueryParams(['account' => (string) $this->account->id])
            ->test('pages::cash.reconciliation')
            ->assertSet('form.account_id', (string) $this->account->id)
            ->set('form.as_of_date', '2026-01-31')
            ->set('form.total_cash', '2000.00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSeeInOrder(['Jan 31, 2026', 'Main', '$2,000.00', '$2,000.00', '$0.00', 'Matches']);
    });

    it('shows the difference and changes nothing when they do not match', function () {
        BrokerBalance::factory()->for($this->account)->create([
            'as_of_date' => '2026-01-31',
            'total_cash' => Money::fromDecimal('2010.00'),
            'settled_cash' => Money::fromDecimal('1990.00'),
        ]);

        Livewire::test('pages::cash.reconciliation')
            ->assertSeeInOrder(['$2,010.00', '$2,000.00', '$10.00', '$1,990.00', '$2,000.00', '-$10.00', 'Difference — look for a missing or wrong entry']);

        expect(AccountOpening::sole()->cash_balance?->format())->toBe('$2,000.00');
    });

    it('refuses a second balance for the same account and date', function () {
        BrokerBalance::factory()->for($this->account)->create(['as_of_date' => '2026-01-31']);

        Livewire::test('pages::cash.reconciliation')
            ->set('form.account_id', (string) $this->account->id)
            ->set('form.as_of_date', '2026-01-31')
            ->set('form.total_cash', '2000')
            ->call('save')
            ->assertHasErrors(['form.as_of_date' => 'unique']);
    });

    it('requires a reason to correct or void a broker balance', function () {
        $balance = BrokerBalance::factory()->for($this->account)->create(['as_of_date' => '2026-01-31', 'total_cash' => Money::fromDecimal('2010')]);

        Livewire::test('pages::cash.broker-balances.edit', ['brokerBalance' => $balance])
            ->set('form.total_cash', '2000')
            ->call('save')
            ->assertHasErrors(['form.reason' => 'required'])
            ->set('form.reason', 'Misread the statement')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Total Cash: $2,010.00 → $2,000.00');
    });
});
