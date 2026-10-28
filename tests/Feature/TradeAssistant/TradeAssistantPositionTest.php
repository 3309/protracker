<?php

use App\Enums\Broker;
use App\Enums\OptionEventType;
use App\Enums\OptionType;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\Dividend;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Models\StockTrade;
use App\Models\User;
use App\Support\Money;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00:00'));
    $this->actingAs(User::factory()->create());
    $this->fidelity = Account::factory()->create(['nickname' => 'Taxable', 'broker' => Broker::Fidelity, 'option_fee_per_contract' => Money::fromCents(65)]);
    $this->robinhood = Account::factory()->create(['nickname' => 'Trading', 'broker' => Broker::Robinhood]);
    $this->put = recordSellToOpen($this->fidelity, OptionType::Put, 'TSLA', '400', '2026-11-20', '2026-10-01', 2, '5.00', '1.30');
});

/**
 * An AI answer about the sold TSLA $400 put expiring Nov 20, 2026, in Fidelity.
 *
 * @param  array<string, string|null>  $overrides
 * @return array<string, mixed>
 */
function aboutTheTslaPut(string $action, Account $account, array $overrides = []): array
{
    return tradeAssistantOutput($action, [
        'account' => (string) $account->id, 'symbol' => 'TSLA', 'option_type' => 'put', 'strike' => '400', 'expiration' => '2026-11-20', ...$overrides,
    ]);
}

describe('buy to close and sell to close', function () {
    it('buys back some of the contracts and leaves the rest open', function () {
        reviewPanel(analyzeTrade(aboutTheTslaPut('buy_to_close', $this->fidelity, ['trade_date' => '2026-10-20', 'quantity' => '1', 'price' => '1.20'])), 'close-review')
            ->assertSet('position_id', (string) $this->put->id)
            ->assertSee('$120.00')
            ->assertSee('This closes 1 of the 2 open contracts')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('trade-assistant'));

        $event = OptionEvent::sole();

        expect($event->event_type)->toBe(OptionEventType::BuyToClose)
            ->and($event->contracts)->toBe(1)
            ->and($event->price_per_share->equals(UnitPrice::fromDecimal('1.20')))->toBeTrue()
            ->and($this->put->fresh()->openContracts())->toBe(1);
    });

    it('sells a bought option to close, choosing only from bought options', function () {
        $call = recordBuyToOpen($this->fidelity, OptionType::Call, 'TSLA', '450', '2026-11-20', '2026-10-02', 1, '4.00', '0.65');

        reviewPanel(analyzeTrade(tradeAssistantOutput('sell_to_close', [
            'account' => (string) $this->fidelity->id, 'symbol' => 'TSLA', 'trade_date' => '2026-10-26', 'quantity' => '1', 'price' => '6.10',
        ])), 'close-review')
            ->assertSet('position_id', (string) $call->id)
            ->assertSee('Proposed sale to close')
            ->call('save')
            ->assertHasNoErrors();

        expect(OptionEvent::sole()->event_type)->toBe(OptionEventType::SellToClose)
            ->and($this->put->fresh()->openContracts())->toBe(2);
    });

    it('refuses to close more contracts than are open', function () {
        reviewPanel(analyzeTrade(aboutTheTslaPut('buy_to_close', $this->fidelity, ['trade_date' => '2026-10-20', 'quantity' => '3', 'price' => '1.20'])), 'close-review')
            ->assertSee('Only 2 of these contracts are open')
            ->call('save')
            ->assertHasErrors('close.contracts');

        expect(OptionEvent::count())->toBe(0);
    });

    it('asks which option is meant when several match, and does not guess', function () {
        $december = recordSellToOpen($this->fidelity, OptionType::Put, 'TSLA', '400', '2026-12-18', '2026-10-01', 1, '7.00', '0.65');

        $panel = reviewPanel(analyzeTrade(aboutTheTslaPut('buy_to_close', $this->fidelity, ['expiration' => null, 'trade_date' => '2026-10-20', 'quantity' => '1', 'price' => '2.00'])), 'close-review')
            ->assertSet('position_id', '')
            ->assertSee('Several open options match')
            ->assertSee('Still needed before saving')
            ->assertSee('Option position');

        $panel->call('save')->assertHasErrors('position_id');
        expect(OptionEvent::count())->toBe(0);

        $panel->set('position_id', (string) $december->id)->call('save')->assertHasNoErrors();
        expect(OptionEvent::sole()->option_position_id)->toBe($december->id);
    });

    it('only lets an option matching the description be chosen', function () {
        $other = recordSellToOpen($this->robinhood, OptionType::Put, 'AAPL', '200', '2026-11-20', '2026-10-01', 1, '3.00', '0.65');

        reviewPanel(analyzeTrade(aboutTheTslaPut('buy_to_close', $this->fidelity, ['trade_date' => '2026-10-20', 'quantity' => '1', 'price' => '1.20'])), 'close-review')
            ->set('position_id', (string) $other->id)
            ->call('save')
            ->assertHasErrors('position_id');

        expect(OptionEvent::count())->toBe(0);
    });

    it('does not offer an option that is already closed', function () {
        recordBuyToClose($this->put, '2026-10-15', 2, '1.00', '1.30');

        reviewPanel(analyzeTrade(aboutTheTslaPut('buy_to_close', $this->fidelity, ['trade_date' => '2026-10-20', 'quantity' => '2', 'price' => '1.20'])), 'close-review')
            ->assertSee('No open option matches')
            ->assertSeeHtml('disabled>Confirm and save');
    });

    it('records a close only once, even when the request is repeated', function () {
        $page = analyzeTrade(aboutTheTslaPut('buy_to_close', $this->fidelity, ['trade_date' => '2026-10-20', 'quantity' => '1', 'price' => '1.20']));

        reviewPanel($page, 'close-review')->call('save')->assertHasNoErrors();
        reviewPanel($page, 'close-review')->call('save')->assertHasErrors('proposal');

        expect($this->put->fresh()->openContracts())->toBe(1);
    });
});

describe('expiry and assignment', function () {
    it('records an expiry of all open contracts on the expiration date, saying both were filled in', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00'));

        reviewPanel(analyzeTrade(aboutTheTslaPut('expire', $this->fidelity)), 'lifecycle-review')
            ->assertSet('lifecycle.contracts', '2')
            ->assertSet('lifecycle.event_date', '2026-11-20')
            ->assertSee('all 2 open contracts are filled in')
            ->assertSee("the option's expiration date, Nov 20, 2026, is filled in")
            ->call('save')
            ->assertHasNoErrors();

        expect(OptionEvent::sole()->event_type)->toBe(OptionEventType::Expired)
            ->and($this->put->fresh()->openContracts())->toBe(0);
    });

    it('refuses to expire an option before its expiration date', function () {
        reviewPanel(analyzeTrade(aboutTheTslaPut('expire', $this->fidelity, ['trade_date' => '2026-10-27', 'quantity' => '2'])), 'lifecycle-review')
            ->assertSee('cannot be recorded as expired before then')
            ->call('save')
            ->assertHasErrors('lifecycle.event_date');

        expect(OptionEvent::count())->toBe(0);
    });

    it('records an assignment through the assignment workflow, buying the shares at the strike', function () {
        reviewPanel(analyzeTrade(aboutTheTslaPut('assign', $this->fidelity, ['trade_date' => '2026-10-27', 'quantity' => '1'])), 'lifecycle-review')
            ->assertSee('Proposed assignment')
            ->call('save')
            ->assertHasNoErrors();

        $purchase = StockTrade::sole();

        expect(OptionEvent::sole()->event_type)->toBe(OptionEventType::Assigned)
            ->and($purchase->side)->toBe(TradeSide::Buy)
            ->and($purchase->shares->format())->toBe('100')
            ->and($purchase->price_per_share->equals(UnitPrice::fromDecimal('400')))->toBeTrue()
            ->and($this->put->fresh()->openContracts())->toBe(1);
    });

    it('only offers sold options for an assignment', function () {
        $this->put->void('Entered by mistake');
        recordBuyToOpen($this->fidelity, OptionType::Put, 'TSLA', '400', '2026-11-20', '2026-10-01', 1, '5.00', '0.65');

        reviewPanel(analyzeTrade(aboutTheTslaPut('assign', $this->fidelity, ['trade_date' => '2026-10-27', 'quantity' => '1'])), 'lifecycle-review')
            ->assertSee('No open option matches');
    });
});

describe('roll', function () {
    it('shows both legs and the net credit, then records them together', function () {
        reviewPanel(analyzeTrade(aboutTheTslaPut('roll', $this->fidelity, [
            'trade_date' => '2026-10-27', 'quantity' => '2', 'price' => '3.00',
            'new_strike' => '390', 'new_expiration' => '2026-12-18', 'new_quantity' => '2', 'new_price' => '5.50',
        ])), 'roll-review')
            ->assertSee('Leg 1: paid to buy back')
            ->assertSee('$600.00')
            ->assertSee('$1,100.00')
            ->assertSee('$500.00')
            ->call('save')
            ->assertHasNoErrors();

        $newLeg = OptionPosition::query()->whereKeyNot($this->put->id)->sole();

        expect($this->put->fresh()->openContracts())->toBe(0)
            ->and($newLeg->strike->equals(UnitPrice::fromDecimal('390')))->toBeTrue()
            ->and($newLeg->expires_on->format('Y-m-d'))->toBe('2026-12-18')
            ->and($newLeg->contracts)->toBe(2);
    });

    it('needs the new option before a roll can be saved, and saves neither leg without it', function () {
        reviewPanel(analyzeTrade(aboutTheTslaPut('roll', $this->fidelity, ['trade_date' => '2026-10-27', 'quantity' => '2', 'price' => '3.00'])), 'roll-review')
            ->assertSee('New strike, New expiration date, New contracts, New premium per share.')
            ->call('save')
            ->assertHasErrors(['roll.new_strike', 'roll.new_expires_on']);

        expect(OptionEvent::count())->toBe(0)
            ->and(OptionPosition::count())->toBe(1);
    });
});

describe('dividend', function () {
    it('records a dividend through the dividend form', function () {
        $page = analyzeTrade(tradeAssistantOutput('dividend', [
            'account' => (string) $this->fidelity->id, 'symbol' => 'KO', 'ex_date' => '2026-09-14', 'trade_date' => '2026-10-01', 'price' => '0.51',
        ]));

        $panel = reviewPanel($page, 'dividend-review')->call('save')->assertHasNoErrors();

        $dividend = Dividend::sole();
        $panel->assertRedirect(route('dividends.edit', $dividend));

        expect($dividend->ticker)->toBe('KO')
            ->and($dividend->pay_date->format('Y-m-d'))->toBe('2026-10-01');
    });

    it('refuses a dividend that is already recorded', function () {
        $answer = tradeAssistantOutput('dividend', [
            'account' => (string) $this->fidelity->id, 'symbol' => 'KO', 'ex_date' => '2026-09-14', 'trade_date' => '2026-10-01', 'price' => '0.51',
        ]);

        reviewPanel(analyzeTrade($answer), 'dividend-review')->call('save')->assertHasNoErrors();
        reviewPanel(analyzeTrade($answer), 'dividend-review')->call('save')->assertHasErrors('form.ex_date');

        expect(Dividend::count())->toBe(1);
    });
});
