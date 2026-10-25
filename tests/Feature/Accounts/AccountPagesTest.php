<?php

use App\Enums\AccountType;
use App\Enums\AuditEvent;
use App\Enums\Broker;
use App\Models\Account;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('index', function () {
    it('lists active accounts before archived ones with their fees', function () {
        Account::factory()->archived()->create(['nickname' => 'Aaa old account']);
        Account::factory()->create(['nickname' => 'Zzz current account', 'broker' => Broker::Fidelity]);

        $this->get(route('accounts.index'))
            ->assertOk()
            ->assertSeeInOrder(['Fidelity · Zzz current account', '$0.67', 'Active', 'Aaa old account', 'Archived']);
    });
});

describe('create', function () {
    it('starts with the default fees filled in', function () {
        Livewire::test('pages::accounts.create')
            ->assertSet('form.option_fee_per_contract', '0.67')
            ->assertSet('form.stock_fee_per_trade', '0.00')
            ->assertSet('form.assignment_fee', '0.00');
    });

    it('creates an account and records a created audit entry', function () {
        Livewire::test('pages::accounts.create')
            ->set('form.broker', 'robinhood')
            ->set('form.nickname', 'Roth IRA')
            ->set('form.account_type', 'ira')
            ->set('form.account_number_last4', '1234')
            ->set('form.option_fee_per_contract', '0.50')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('accounts.index'));

        $account = Account::sole();

        expect($account->broker)->toBe(Broker::Robinhood)
            ->and($account->account_type)->toBe(AccountType::Ira)
            ->and($account->account_number_last4)->toBe('1234')
            ->and($account->option_fee_per_contract->cents)->toBe(50)
            ->and($account->stock_fee_per_trade->cents)->toBe(0);

        $entry = $account->auditEntries()->sole();

        expect($entry->event)->toBe(AuditEvent::Created)
            ->and($entry->user_id)->toBe($this->user->id)
            ->and($entry->new_values)->toMatchArray(['nickname' => 'Roth IRA', 'option_fee_per_contract' => 50]);
    });

    it('requires a broker, nickname and account type', function () {
        Livewire::test('pages::accounts.create')
            ->call('save')
            ->assertHasErrors([
                'form.broker' => 'required',
                'form.nickname' => 'required',
                'form.account_type' => 'required',
            ]);

        expect(Account::count())->toBe(0);
    });

    it('rejects fees that are not dollar amounts with at most two decimal places', function (string $fee) {
        Livewire::test('pages::accounts.create')
            ->set('form.broker', 'fidelity')
            ->set('form.nickname', 'Main')
            ->set('form.account_type', 'taxable')
            ->set('form.option_fee_per_contract', $fee)
            ->call('save')
            ->assertHasErrors('form.option_fee_per_contract')
            ->assertSee('Enter a dollar amount such as 0.67, with no more than two decimal places.');
    })->with(['fraction of a cent' => '0.675', 'negative' => '-0.67', 'text' => 'free']);

    it('rejects a nickname that is already used', function () {
        Account::factory()->create(['nickname' => 'Main']);

        Livewire::test('pages::accounts.create')
            ->set('form.broker', 'fidelity')
            ->set('form.nickname', 'Main')
            ->set('form.account_type', 'taxable')
            ->call('save')
            ->assertHasErrors(['form.nickname' => 'unique']);
    });

    it('accepts only exactly four digits for the account number', function () {
        Livewire::test('pages::accounts.create')
            ->set('form.broker', 'fidelity')
            ->set('form.nickname', 'Main')
            ->set('form.account_type', 'taxable')
            ->set('form.account_number_last4', '12345678')
            ->call('save')
            ->assertHasErrors(['form.account_number_last4' => 'digits']);
    });
});

describe('edit', function () {
    it('requires a reason before saving a change', function () {
        $account = Account::factory()->create();

        Livewire::test('pages::accounts.edit', ['account' => $account])
            ->set('form.option_fee_per_contract', '0.71')
            ->call('save')
            ->assertHasErrors(['form.reason' => 'required']);

        expect($account->refresh()->option_fee_per_contract->cents)->toBe(67);
    });

    it('saves a change and records the original value, new value and reason', function () {
        $account = Account::factory()->create();

        Livewire::test('pages::accounts.edit', ['account' => $account])
            ->set('form.option_fee_per_contract', '0.71')
            ->set('form.reason', 'Broker raised its fee')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('form.reason', '')
            ->assertSee('Option Fee Per Contract: $0.67 → $0.71')
            ->assertSee('Reason: Broker raised its fee');

        expect($account->refresh()->option_fee_per_contract->cents)->toBe(71);

        $entry = $account->auditEntries()->where('event', AuditEvent::Updated)->sole();

        expect($entry->old_values)->toBe(['option_fee_per_contract' => 67])
            ->and($entry->new_values)->toBe(['option_fee_per_contract' => 71])
            ->and($entry->reason)->toBe('Broker raised its fee')
            ->and($entry->user_id)->toBe($this->user->id);
    });

    it('archives an account without deleting it', function () {
        $account = Account::factory()->create();

        Livewire::test('pages::accounts.edit', ['account' => $account])
            ->set('form.reason', 'Account closed')
            ->call('archive')
            ->assertHasNoErrors()
            ->assertSee('Account archived.');

        expect($account->refresh()->isArchived())->toBeTrue()
            ->and($account->auditEntries()->latest('id')->first())
            ->event->toBe(AuditEvent::Archived)
            ->reason->toBe('Account closed');
    });

    it('requires a reason before archiving', function () {
        $account = Account::factory()->create();

        Livewire::test('pages::accounts.edit', ['account' => $account])
            ->call('archive')
            ->assertHasErrors(['form.reason' => 'required']);

        expect($account->refresh()->isArchived())->toBeFalse();
    });

    it('restores an archived account', function () {
        $account = Account::factory()->archived()->create();

        Livewire::test('pages::accounts.edit', ['account' => $account])
            ->set('form.reason', 'Reopened')
            ->call('restore')
            ->assertHasNoErrors();

        expect($account->refresh()->isArchived())->toBeFalse()
            ->and($account->auditEntries()->latest('id')->first()->event)->toBe(AuditEvent::Restored);
    });

    it('returns 404 for an account that does not exist', function () {
        $this->get('/accounts/999/edit')->assertNotFound();
    });
});
