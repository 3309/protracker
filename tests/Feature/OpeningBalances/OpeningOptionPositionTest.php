<?php

use App\Enums\AuditEvent;
use App\Enums\OptionSide;
use App\Enums\OptionType;
use App\Enums\PositionOrigin;
use App\Models\Account;
use App\Models\OptionPosition;
use App\Models\User;
use App\Support\Money;
use App\Support\UnitPrice;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->create();
});

function fillValidOption(Testable $page): Testable
{
    return $page
        ->set('form.underlying', 'xyz')
        ->set('form.option_type', 'put')
        ->set('form.strike', '50')
        ->set('form.opened_on', '2025-12-05')
        ->set('form.expires_on', '2026-01-16')
        ->set('form.contracts', '3')
        ->set('form.premium_per_share', '1.20')
        ->set('form.source', 'broker_statement');
}

describe('create', function () {
    it('adds a short option still open on the reporting start date', function () {
        fillValidOption(Livewire::test('pages::opening-balances.options.create', ['account' => $this->account]))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('opening-balances.show', $this->account));

        $option = OptionPosition::sole();

        expect($option->account_id)->toBe($this->account->id)
            ->and($option->underlying)->toBe('XYZ')
            ->and($option->option_type)->toBe(OptionType::Put)
            ->and($option->side)->toBe(OptionSide::Short)
            ->and($option->origin)->toBe(PositionOrigin::OpeningBalance)
            ->and($option->strike->format())->toBe('$50.00')
            ->and($option->opened_on->format('Y-m-d'))->toBe('2025-12-05')
            ->and($option->expires_on->format('Y-m-d'))->toBe('2026-01-16')
            ->and($option->contracts)->toBe(3)
            ->and($option->multiplier)->toBe(100)
            ->and($option->premiumReceived()->format())->toBe('$360.00')
            ->and($option->description())->toBe('XYZ $50.00 Put, expires Jan 16, 2026')
            ->and($option->auditEntries()->sole()->event)->toBe(AuditEvent::Created);
    });

    it('uses the account default fee per contract times the contracts when the fee is left blank', function () {
        fillValidOption(Livewire::test('pages::opening-balances.options.create', ['account' => $this->account]))
            ->call('save')
            ->assertHasNoErrors();

        expect(OptionPosition::sole()->opening_fees->cents)->toBe(201);
    });

    it("uses this account's own default fee", function () {
        $account = Account::factory()->create(['option_fee_per_contract' => Money::fromDecimal('0.50')]);

        fillValidOption(Livewire::test('pages::opening-balances.options.create', ['account' => $account]))
            ->set('form.contracts', '2')
            ->call('save')
            ->assertHasNoErrors();

        expect(OptionPosition::sole()->opening_fees->cents)->toBe(100);
    });

    it('keeps an actual fee entered from the broker records', function () {
        fillValidOption(Livewire::test('pages::opening-balances.options.create', ['account' => $this->account]))
            ->set('form.opening_fees', '2.13')
            ->call('save')
            ->assertHasNoErrors();

        expect(OptionPosition::sole()->opening_fees->cents)->toBe(213);
    });

    it('accepts an actual fee of zero', function () {
        fillValidOption(Livewire::test('pages::opening-balances.options.create', ['account' => $this->account]))
            ->set('form.opening_fees', '0')
            ->call('save')
            ->assertHasNoErrors();

        expect(OptionPosition::sole()->opening_fees->cents)->toBe(0);
    });

    it('uses the stored contract multiplier for the premium', function () {
        fillValidOption(Livewire::test('pages::opening-balances.options.create', ['account' => $this->account]))
            ->set('form.contracts', '1')
            ->set('form.multiplier', '10')
            ->call('save')
            ->assertHasNoErrors();

        expect(OptionPosition::sole()->premiumReceived()->format())->toBe('$12.00');
    });

    it('rejects an option opened on or after the reporting start date', function () {
        fillValidOption(Livewire::test('pages::opening-balances.options.create', ['account' => $this->account]))
            ->set('form.opened_on', '2026-01-01')
            ->set('form.expires_on', '2026-02-20')
            ->call('save')
            ->assertHasErrors(['form.opened_on' => 'before'])
            ->assertSee('Enter later options as trades.');
    });

    it('rejects an option that expired before the reporting start date', function () {
        fillValidOption(Livewire::test('pages::opening-balances.options.create', ['account' => $this->account]))
            ->set('form.expires_on', '2025-12-31')
            ->call('save')
            ->assertHasErrors(['form.expires_on' => 'after_or_equal'])
            ->assertSee('so it was not open on that date');
    });

    it('accepts an option expiring on the reporting start date itself', function () {
        fillValidOption(Livewire::test('pages::opening-balances.options.create', ['account' => $this->account]))
            ->set('form.expires_on', '2026-01-01')
            ->call('save')
            ->assertHasNoErrors();
    });

    it('rejects invalid values', function (string $field, string $value) {
        fillValidOption(Livewire::test('pages::opening-balances.options.create', ['account' => $this->account]))
            ->set($field, $value)
            ->call('save')
            ->assertHasErrors($field);

        expect(OptionPosition::count())->toBe(0);
    })->with([
        'zero contracts' => ['form.contracts', '0'],
        'part of a contract' => ['form.contracts', '1.5'],
        'zero multiplier' => ['form.multiplier', '0'],
        'zero premium' => ['form.premium_per_share', '0'],
        'premium with five decimal places' => ['form.premium_per_share', '1.20001'],
        'zero strike' => ['form.strike', '0.00'],
        'neither put nor call' => ['form.option_type', 'straddle'],
        'fee with a fraction of a cent' => ['form.opening_fees', '2.015'],
        'missing source' => ['form.source', ''],
    ]);
});

describe('edit', function () {
    it('requires a reason and records a corrected fee', function () {
        $option = OptionPosition::factory()->for($this->account)->create(['opening_fees' => Money::fromCents(201)]);

        Livewire::test('pages::opening-balances.options.edit', ['optionPosition' => $option])
            ->assertSet('form.opening_fees', '2.01')
            ->set('form.opening_fees', '2.13')
            ->call('save')
            ->assertHasErrors(['form.reason' => 'required'])
            ->set('form.reason', 'Actual fee from the statement')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Opening Fees: $2.01 → $2.13');

        expect($option->refresh()->opening_fees->cents)->toBe(213);
    });

    it('voids an option with a reason and keeps it in the history', function () {
        $option = OptionPosition::factory()->for($this->account)->create();

        Livewire::test('pages::opening-balances.options.edit', ['optionPosition' => $option])
            ->set('form.reason', 'Closed in December, entered by mistake')
            ->call('void')
            ->assertHasNoErrors()
            ->assertSee('Option voided.');

        expect($option->refresh()->isVoided())->toBeTrue()
            ->and($option->auditEntries()->latest('id')->first()->event)->toBe(AuditEvent::Voided);

        $this->get(route('opening-balances.show', $this->account))->assertSee('Voided');
    });

    it('does not allow a voided option to be changed', function () {
        $option = OptionPosition::factory()->for($this->account)->voided()->create();

        Livewire::test('pages::opening-balances.options.edit', ['optionPosition' => $option])
            ->set('form.reason', 'Trying anyway')
            ->call('save')
            ->assertForbidden();
    });
});

it('shows open options with their premium and fees on the account page', function () {
    OptionPosition::factory()->for($this->account)->create([
        'underlying' => 'XYZ',
        'option_type' => OptionType::Call,
        'strike' => UnitPrice::fromDecimal('52'),
        'expires_on' => '2026-02-20',
        'contracts' => 1,
        'premium_per_share' => UnitPrice::fromDecimal('0.80'),
        'opening_fees' => Money::fromCents(67),
    ]);

    $this->get(route('opening-balances.show', $this->account))
        ->assertSeeInOrder(['XYZ $52.00 Call, expires Feb 20, 2026', '$0.80', '$80.00', '$0.67']);
});
