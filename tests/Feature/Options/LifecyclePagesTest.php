<?php

use App\Enums\OptionEventType;
use App\Enums\OptionType;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Models\StockTrade;
use App\Models\User;
use App\Support\Money;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->create(['assignment_fee' => Money::fromDecimal('1.00')]);
    $this->put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 1, '1.20', '0.67');
});

it('sends guests to the login page from the roll chain', function () {
    $this->app['auth']->logout();

    $this->get(route('options.chain', $this->put))->assertRedirect(route('login'));
});

describe('position page', function () {
    it('records an expiry prefilled with the expiration date and the open contracts', function () {
        Livewire::test('pages::options.show', ['optionPosition' => $this->put])
            ->call('chooseAction', 'expired')
            ->assertSet('lifecycle.event_date', '2026-03-20')
            ->assertSet('lifecycle.contracts', '1')
            ->call('recordLifecycleEvent')
            ->assertHasNoErrors()
            ->assertSee('1 contract expired worthless. Net realized option P/L: $119.33.')
            ->assertSee('Expired worthless');

        expect(OptionEvent::sole()->event_type)->toBe(OptionEventType::Expired);
    });

    it('records a put assignment and its stock purchase with the default assignment fee', function () {
        Livewire::test('pages::options.show', ['optionPosition' => $this->put])
            ->call('chooseAction', 'assigned')
            ->assertSee('Buy contracts × 100 XYZ shares at the strike, $50.00.')
            ->call('recordLifecycleEvent')
            ->assertHasNoErrors()
            ->assertSee('Assigned 1 contract. The stock purchase at the strike has been recorded.')
            ->assertSee('Stock Buy 100 at $50.00');

        expect(StockTrade::sole())
            ->side->toBe(TradeSide::Buy)
            ->fees->format()->toBe('$1.00');
    });

    it('explains when a call is assigned without enough shares', function () {
        $call = recordSellToOpen($this->account, OptionType::Call, 'XYZ', '52', '2026-03-20', '2026-02-02', 1, '0.80', '0.67');

        Livewire::test('pages::options.show', ['optionPosition' => $call])
            ->call('chooseAction', 'assigned')
            ->call('recordLifecycleEvent')
            ->assertHasErrors('lifecycle.contracts')
            ->assertSee('Not enough XYZ shares in this account for the sale on Mar 20, 2026: 100 needed, 0 available.');

        expect(OptionEvent::count())->toBe(0);
    });

    it('records a roll and opens the new leg with its net credit', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-02-02', '100', '50');
        $call = recordSellToOpen($this->account, OptionType::Call, 'XYZ', '52', '2026-03-20', '2026-02-23', 1, '0.80', '0.67');

        Livewire::test('pages::options.show', ['optionPosition' => $call])
            ->call('chooseAction', 'roll')
            ->assertSet('roll.contracts', '1')
            ->assertSet('roll.new_strike', '52')
            ->set('roll.event_date', '2026-03-13')
            ->set('roll.price_per_share', '1.50')
            ->set('roll.new_strike', '55')
            ->set('roll.new_expires_on', '2026-04-17')
            ->set('roll.new_premium_per_share', '1.60')
            ->call('recordRoll')
            ->assertHasNoErrors()
            ->assertRedirect(route('options.show', OptionPosition::query()->latest('id')->first()));

        $newLeg = OptionPosition::query()->latest('id')->first();

        expect($newLeg->rollNetCredit()?->format())->toBe('$8.66')
            ->and($newLeg->opening_fees->format())->toBe('$0.67');

        Livewire::test('pages::options.show', ['optionPosition' => $newLeg])
            ->assertSee('Opened by rolling')
            ->assertSee('roll net credit $8.66')
            ->assertSee('Roll chain');
    });

    it('refuses a roll whose new expiration is before the roll date', function () {
        Livewire::test('pages::options.show', ['optionPosition' => $this->put])
            ->call('chooseAction', 'roll')
            ->set('roll.event_date', '2026-03-13')
            ->set('roll.price_per_share', '1.50')
            ->set('roll.new_expires_on', '2026-03-12')
            ->set('roll.new_premium_per_share', '1.60')
            ->call('recordRoll')
            ->assertHasErrors('roll.new_expires_on');
    });
});

describe('roll chain page', function () {
    it('Example C: shows the closed leg, the roll credit and the chain result', function () {
        $call = recordSellToOpen($this->account, OptionType::Call, 'XYZ', '52', '2026-03-20', '2026-02-23', 1, '0.80', '0.67');
        $newLeg = recordRoll($call, '2026-03-13', 1, '1.50', '0.67', '55', '2026-04-17', 1, '1.60', '0.67');

        Livewire::test('pages::options.chain', ['optionPosition' => $newLeg])
            ->assertSee('Chain result to date (realized)')
            ->assertSee('-$71.34')
            ->assertSeeInOrder(['XYZ $52.00 Call', '$80.00', '—', '-$71.34', 'Closed', 'XYZ $55.00 Call', '$160.00', '$8.66', '$0.00', 'Open (1)']);

        recordExpiry($newLeg, '2026-04-17', 1);

        Livewire::test('pages::options.chain', ['optionPosition' => $call])
            ->assertSeeInOrder(['Chain cumulative result', '$87.99'])
            ->assertSee('$159.33');
    });

    it('is not available for an option that was never rolled', function () {
        $this->get(route('options.chain', $this->put))->assertNotFound();
    });
});

describe('correcting events', function () {
    it('corrects an assignment and its stock trade together', function () {
        $put = recordSellToOpen($this->account, OptionType::Put, 'ABC', '20', '2026-03-20', '2026-02-02', 2, '1.00', '1.34');
        $assignment = recordAssignment($put, '2026-03-20', 1);

        Livewire::test('pages::options.events.edit', ['optionEvent' => $assignment])
            ->assertSet('lifecycle.contracts', '1')
            ->set('lifecycle.contracts', '2')
            ->call('save')
            ->assertHasErrors(['lifecycle.reason' => 'required'])
            ->set('lifecycle.reason', 'Both contracts were assigned')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Contracts: 1 → 2');

        expect($assignment->stockTrade->fresh()->shares->format())->toBe('200');
    });

    it('voids an assignment together with its stock trade', function () {
        $assignment = recordAssignment($this->put, '2026-03-20', 1);

        Livewire::test('pages::options.events.edit', ['optionEvent' => $assignment])
            ->set('lifecycle.reason', 'Reversed by broker')
            ->call('void')
            ->assertHasNoErrors()
            ->assertSee('Event voided.');

        expect($assignment->stockTrade->fresh()->isVoided())->toBeTrue();
    });

    it("points to the new leg instead of offering to void a roll's buy-back", function () {
        $newLeg = recordRoll($this->put, '2026-03-13', 1, '1.50', '0.67', '48', '2026-04-17', 1, '1.60', '0.67');

        Livewire::test('pages::options.events.edit', ['optionEvent' => $this->put->events()->sole()])
            ->assertSee('To undo the roll, void that option.')
            ->assertDontSee('Void event')
            ->set('form.reason', 'Trying anyway')
            ->call('void')
            ->assertHasErrors('form.reason');

        expect($newLeg->fresh()->isVoided())->toBeFalse();
    });

    it('only lets an assignment stock trade be changed through its assignment', function () {
        $trade = recordAssignment($this->put, '2026-03-20', 1)->stockTrade;

        Livewire::test('pages::stock-trades.edit', ['stockTrade' => $trade])
            ->assertSee('This trade was created by an option assignment at the strike.')
            ->set('form.reason', 'Trying anyway')
            ->call('save')
            ->assertForbidden();
    });
});

it('warns about open options past their expiration date', function () {
    $this->travelTo(now()->setDate(2026, 3, 24)->setTime(12, 0));

    Livewire::test('pages::options.index')
        ->assertSee('1 option still open after the expiration date.')
        ->assertSee('Past expiration — record expiry or assignment');

    recordExpiry($this->put, '2026-03-20', 1);

    Livewire::test('pages::options.index')->assertDontSee('still open after the expiration date');
});
