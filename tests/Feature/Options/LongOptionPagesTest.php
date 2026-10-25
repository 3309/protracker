<?php

use App\Enums\OptionEventType;
use App\Enums\OptionSide;
use App\Enums\OptionType;
use App\Enums\PositionOrigin;
use App\Models\Account;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->create(['nickname' => 'Main']);
});

function fillValidBuyToOpen(Testable $page, Account $account): Testable
{
    return $page
        ->set('form.account_id', (string) $account->id)
        ->set('form.underlying', 'tsla')
        ->set('form.strike', '250')
        ->set('form.opened_on', '2026-07-02')
        ->set('form.expires_on', '2026-07-17')
        ->set('form.contracts', '2')
        ->set('form.premium_per_share', '3.50');
}

describe('buy to open', function () {
    it('offers Buy call and Buy put next to Sell to open', function () {
        $this->get(route('options.index'))
            ->assertOk()
            ->assertSee('Sell to open')
            ->assertSee('Buy option')
            ->assertSee(route('options.buy', ['type' => 'call']), escape: false)
            ->assertSee(route('options.buy', ['type' => 'put']), escape: false);
    });

    it('preselects call or put from the choice', function (string $type, string $heading) {
        $this->get(route('options.buy', ['type' => $type]))->assertOk()->assertSee($heading);

        Livewire::test('pages::options.buy', ['type' => $type])->assertSet('form.option_type', $type);
    })->with([
        'call' => ['call', 'Buy call'],
        'put' => ['put', 'Buy put'],
    ]);

    it('shows the premium paid, fees and total cost while typing', function () {
        fillValidBuyToOpen(Livewire::test('pages::options.buy', ['type' => 'call']), $this->account)
            ->assertSeeInOrder(['Premium paid', '$700.00', 'Opening fees', '$1.34', 'Total opening cost', '$701.34'])
            ->set('form.opening_fees', '0')
            ->assertSeeInOrder(['Total opening cost', '$700.00']);
    });

    it('records a long call with the default fee and opens its page', function () {
        fillValidBuyToOpen(Livewire::test('pages::options.buy', ['type' => 'call']), $this->account)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('options.show', OptionPosition::sole()));

        expect(OptionPosition::sole())
            ->side->toBe(OptionSide::Long)
            ->origin->toBe(PositionOrigin::BuyToOpen)
            ->option_type->toBe(OptionType::Call)
            ->underlying->toBe('TSLA')
            ->opening_fees->format()->toBe('$1.34');
    });

    it('rejects invalid values and saves nothing', function (string $field, string $value) {
        fillValidBuyToOpen(Livewire::test('pages::options.buy', ['type' => 'call']), $this->account)
            ->set($field, $value)
            ->call('save')
            ->assertHasErrors('form.'.str($field)->after('form.'));

        expect(OptionPosition::count())->toBe(0);
    })->with([
        'no contracts' => ['form.contracts', '0'],
        'no premium' => ['form.premium_per_share', '0'],
        'a strike of zero' => ['form.strike', '0'],
        'expiring before the trade' => ['form.expires_on', '2026-07-01'],
        'no call or put' => ['form.option_type', ''],
    ]);
});

describe('a bought option\'s page', function () {
    beforeEach(function () {
        $this->call = recordBuyToOpen($this->account, OptionType::Call, 'TSLA', '250', '2026-07-17', '2026-07-02', 5, '2.00', '3.35');
    });

    it('offers only sell to close and expired worthless', function () {
        Livewire::test('pages::options.show', ['optionPosition' => $this->call])
            ->assertSee(['Long (bought)', 'Premium paid', '$1,000.00', 'Total opening cost', '$1,003.35'])
            ->assertSeeInOrder(['Sell to close', 'Expired worthless'])
            ->assertDontSee('Buy to close')
            ->assertDontSee('Roll')
            ->call('chooseAction', 'assigned')
            ->assertNotFound();
    });

    it('sells some contracts to close and shows what is still open', function () {
        Livewire::test('pages::options.show', ['optionPosition' => $this->call])
            ->assertSet('action', 'sell_to_close')
            ->set('close.event_date', '2026-07-10')
            ->set('close.contracts', '2')
            ->set('close.price_per_share', '3.00')
            ->call('buyToClose')
            ->assertHasNoErrors()
            ->assertSee('Sold to close 2 contracts. Net realized P/L: $197.32.')
            ->assertSee('$600.00 / $2.01');

        expect(OptionEvent::sole()->event_type)->toBe(OptionEventType::SellToClose);
    });

    it('refuses to sell more contracts than are open', function () {
        Livewire::test('pages::options.show', ['optionPosition' => $this->call])
            ->set('close.event_date', '2026-07-10')
            ->set('close.contracts', '6')
            ->set('close.price_per_share', '3.00')
            ->call('buyToClose')
            ->assertHasErrors('close.contracts');

        expect(OptionEvent::count())->toBe(0);
    });

    it('corrects a sale to close on the event page', function () {
        $sale = recordSellToClose($this->call, '2026-07-10', 2, '3.00', '1.34');

        Livewire::test('pages::options.events.edit', ['optionEvent' => $sale])
            ->assertSee('Sold to close')
            ->assertSee('Contracts sold')
            ->set('form.price_per_share', '3.50')
            ->set('form.reason', 'Price from the broker statement')
            ->call('save')
            ->assertHasNoErrors();

        expect($sale->fresh()?->netProfit()->format())->toBe('$297.32');
    });
});

it('filters the options list by call or put, sold or bought, and expiration month', function (array $filter, string $shown, string $hidden) {
    recordBuyToOpen($this->account, OptionType::Call, 'TSLA', '250', '2026-07-17', '2026-07-02', 1, '2.00', '0.67');
    recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-08-21', '2026-07-02', 1, '1.20', '0.67');

    Livewire::withQueryParams($filter)
        ->test('pages::options.index')
        ->assertSee($shown)
        ->assertDontSee($hidden);
})->with([
    'bought only' => [['side' => 'long'], 'TSLA $250.00 Call (long)', 'XYZ $50.00 Put'],
    'puts only' => [['type' => 'put'], 'XYZ $50.00 Put', 'TSLA $250.00 Call'],
    'expiring in August' => [['expiry' => '2026-08'], 'XYZ $50.00 Put', 'TSLA $250.00 Call'],
]);
