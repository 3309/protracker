<?php

use App\Actions\Options\SaveOptionTrade;
use App\Enums\OptionType;
use App\Enums\PositionOrigin;
use App\Models\Account;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Models\User;
use App\Support\Money;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->create(['nickname' => 'Main']);
});

function fillValidSellToOpen(Testable $page, Account $account): Testable
{
    return $page
        ->set('form.account_id', (string) $account->id)
        ->set('form.underlying', 'xyz')
        ->set('form.option_type', 'put')
        ->set('form.strike', '50')
        ->set('form.opened_on', '2026-07-02')
        ->set('form.expires_on', '2026-07-17')
        ->set('form.contracts', '3')
        ->set('form.premium_per_share', '1.20');
}

describe('sell to open', function () {
    it('records the sale with the default fee per contract and a calculated settlement date', function () {
        fillValidSellToOpen(Livewire::test('pages::options.create'), $this->account)
            ->call('save')
            ->assertHasNoErrors();

        $position = OptionPosition::sole();

        expect($position->origin)->toBe(PositionOrigin::SellToOpen)
            ->and($position->underlying)->toBe('XYZ')
            ->and($position->opening_fees->format())->toBe('$2.01')
            ->and($position->opening_settlement_date?->format('Y-m-d'))->toBe('2026-07-06')
            ->and($position->opening_settlement_date_overridden)->toBeFalse()
            ->and($position->premiumReceived()->format())->toBe('$360.00');
    });

    it("uses the account's own fee, and keeps an actual fee of zero", function () {
        $account = Account::factory()->create(['option_fee_per_contract' => Money::fromDecimal('0.50')]);

        fillValidSellToOpen(Livewire::test('pages::options.create'), $account)->call('save');
        fillValidSellToOpen(Livewire::test('pages::options.create'), $account)->set('form.opening_fees', '0')->call('save');

        expect(OptionPosition::query()->orderBy('id')->pluck('opening_fees')->map->cents->all())->toBe([150, 0]);
    });

    it('rejects invalid values', function (string $field, string $value) {
        fillValidSellToOpen(Livewire::test('pages::options.create'), $this->account)
            ->set($field, $value)
            ->call('save')
            ->assertHasErrors($field);

        expect(OptionPosition::count())->toBe(0);
    })->with([
        'sold before the reporting start date' => ['form.opened_on', '2025-12-31'],
        'expires before the trade date' => ['form.expires_on', '2026-07-01'],
        'settles before the trade date' => ['form.settlement_date', '2026-07-01'],
        'zero contracts' => ['form.contracts', '0'],
        'part of a contract' => ['form.contracts', '1.5'],
        'zero premium' => ['form.premium_per_share', '0'],
        'fee with a fraction of a cent' => ['form.opening_fees', '2.015'],
        'missing account' => ['form.account_id', ''],
    ]);
});

describe('position page', function () {
    beforeEach(function () {
        $this->put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 3, '1.20', '2.01');
    });

    it('buys contracts back to close with the default fee and shows the realized profit', function () {
        Livewire::test('pages::options.show', ['optionPosition' => $this->put])
            ->assertSet('close.contracts', '3')
            ->set('close.event_date', '2026-02-20')
            ->set('close.contracts', '1')
            ->set('close.price_per_share', '0.40')
            ->call('buyToClose')
            ->assertHasNoErrors()
            ->assertSee('Bought to close 1 contract. Net realized P/L: $78.66.')
            ->assertSet('close.contracts', '2')
            ->assertSeeInOrder(['Feb 20, 2026', 'Bought to close', '1', '$0.40', '$120.00', '$40.00', '$80.00', '$1.34', '$78.66']);

        expect(OptionEvent::sole())
            ->fees->format()->toBe('$0.67')
            ->settlement_date->format('Y-m-d')->toBe('2026-02-23');
    });

    it('explains when more contracts would be closed than are open', function () {
        Livewire::test('pages::options.show', ['optionPosition' => $this->put])
            ->set('close.event_date', '2026-02-20')
            ->set('close.contracts', '4')
            ->set('close.price_per_share', '0.40')
            ->call('buyToClose')
            ->assertHasErrors('close.contracts')
            ->assertSee('has 3 contracts, but 4 would be closed');

        expect(OptionEvent::count())->toBe(0);
    });

    it('explains when the close is after expiration', function () {
        Livewire::test('pages::options.show', ['optionPosition' => $this->put])
            ->set('close.event_date', '2026-03-23')
            ->set('close.price_per_share', '0.40')
            ->call('buyToClose')
            ->assertHasErrors('close.event_date');
    });

    it('hides the buy-to-close form once every contract is closed', function () {
        recordBuyToClose($this->put, '2026-02-20', 3, '0.40', '2.01');

        Livewire::test('pages::options.show', ['optionPosition' => $this->put->fresh()])
            ->assertSee('Closed')
            ->assertDontSee('Record buy to close');
    });
});

describe('list', function () {
    it('shows open positions by default and closed ones on request', function () {
        $open = recordSellToOpen($this->account, OptionType::Put, 'OPN', '50', '2026-03-20', '2026-02-02', 1, '1.20', '0.67');
        $closed = recordSellToOpen($this->account, OptionType::Call, 'CLS', '25', '2026-03-20', '2026-02-02', 1, '1.00', '0.67');
        recordBuyToClose($closed, '2026-02-10', 1, '0.20', '0.67');

        Livewire::test('pages::options.index')
            ->assertSee('OPN $50.00 Put')
            ->assertDontSee('CLS $25.00 Call');

        Livewire::withQueryParams(['status' => 'closed'])
            ->test('pages::options.index')
            ->assertSee('CLS $25.00 Call')
            ->assertDontSee('OPN $50.00 Put');
    });

    it('shows days to expiry for open positions', function () {
        $this->travelTo(now()->setDate(2026, 3, 10)->setTime(12, 0));
        recordSellToOpen($this->account, OptionType::Put, 'OPN', '50', '2026-03-20', '2026-02-02', 1, '1.20', '0.67');

        Livewire::test('pages::options.index')->assertSeeInOrder(['OPN $50.00 Put', '1 / 1', '$120.00', '$0.67', '10']);
    });
});

describe('corrections', function () {
    beforeEach(function () {
        $this->put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 1, '1.20', '0.67');
    });

    it('require a reason and recalculate the closes', function () {
        $close = recordBuyToClose($this->put, '2026-02-20', 1, '0.40', '0.67');

        Livewire::test('pages::options.edit', ['optionPosition' => $this->put])
            ->set('form.premium_per_share', '1.50')
            ->call('save')
            ->assertHasErrors(['form.reason' => 'required'])
            ->set('form.reason', 'Premium from confirmation')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Premium Per Share: $1.20 → $1.50');

        expect($close->fresh()->allocated_premium->format())->toBe('$150.00');
    });

    it('explain why a sale with closes cannot be voided', function () {
        recordBuyToClose($this->put, '2026-02-20', 1, '0.40', '0.67');

        Livewire::test('pages::options.edit', ['optionPosition' => $this->put])
            ->set('form.reason', 'Mistake')
            ->call('void')
            ->assertHasErrors('form.reason')
            ->assertSee('has recorded closes. Void those first, then void the position.');
    });

    it('of a close require a reason, and voiding it opens the contracts again', function () {
        $close = recordBuyToClose($this->put, '2026-02-20', 1, '0.40', '0.67');

        Livewire::test('pages::options.events.edit', ['optionEvent' => $close])
            ->set('form.price_per_share', '0.30')
            ->call('save')
            ->assertHasErrors(['form.reason' => 'required'])
            ->set('form.reason', 'Fill price')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Price Per Share: $0.40 → $0.30')
            ->set('form.reason', 'Did not happen')
            ->call('void')
            ->assertSee('Close voided.');

        expect($this->put->fresh()->openContracts())->toBe(1);
    });

    it('of an opening-balance option are made on the opening balance page', function () {
        $opening = OptionPosition::factory()->for($this->account)->create();

        Livewire::test('pages::options.show', ['optionPosition' => $opening])
            ->assertSee(route('opening-balances.options.edit', $opening));

        $this->get(route('options.edit', $opening))->assertNotFound();
    });

    it('of an opening-balance option cannot drop below the contracts already closed', function () {
        $opening = OptionPosition::factory()->for($this->account)->create(['contracts' => 2, 'opened_on' => '2025-12-01', 'expires_on' => '2026-02-20']);
        recordBuyToClose($opening, '2026-01-09', 2, '0.10', '0');

        Livewire::test('pages::opening-balances.options.edit', ['optionPosition' => $opening])
            ->set('form.contracts', '1')
            ->set('form.reason', 'Fewer contracts')
            ->call('save')
            ->assertHasErrors('form.contracts');

        expect($opening->fresh()->contracts)->toBe(2);
    });

    it('do not allow a voided sale to be changed', function () {
        app(SaveOptionTrade::class)->voidPosition($this->put, 'Mistake');

        Livewire::test('pages::options.edit', ['optionPosition' => $this->put->fresh()])
            ->assertSee('This sale was voided.')
            ->set('form.reason', 'Trying anyway')
            ->call('save')
            ->assertForbidden();
    });
});
