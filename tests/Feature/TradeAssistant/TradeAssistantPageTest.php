<?php

use App\Enums\Broker;
use App\Enums\OptionSide;
use App\Enums\OptionType;
use App\Enums\TradeSide;
use App\Exceptions\TradeAssistantException;
use App\Models\Account;
use App\Models\CashMovement;
use App\Models\OptionPosition;
use App\Models\StockTrade;
use App\Models\User;
use App\Support\Money;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00:00'));
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->fidelity = Account::factory()->create(['nickname' => 'Taxable', 'broker' => Broker::Fidelity, 'option_fee_per_contract' => Money::fromCents(65)]);
    $this->robinhood = Account::factory()->create(['nickname' => 'Trading', 'broker' => Broker::Robinhood]);
});

function stockPurchase(Account $account, array $overrides = []): array
{
    return tradeAssistantOutput('buy_stock', [
        'account' => (string) $account->id, 'symbol' => 'TSLA', 'trade_date' => '2026-06-12', 'quantity' => '100', 'price' => '400', ...$overrides,
    ]);
}

function soldPut(Account $account, array $overrides = []): array
{
    return tradeAssistantOutput('sell_to_open', [
        'account' => (string) $account->id, 'symbol' => 'TSLA', 'option_type' => 'put', 'strike' => '400', 'expiration' => '2027-01-15',
        'trade_date' => '2026-06-10', 'quantity' => '2', 'price' => '2.50', 'multiplier' => '100', ...$overrides,
    ]);
}

it('sends guests to the login page', function () {
    $this->app['auth']->logout();

    $this->get(route('trade-assistant'))->assertRedirect(route('login'));
});

it('opens from the menu', function () {
    $this->get(route('trade-assistant'))->assertOk()->assertSee('Analyze Trade');
});

describe('analyze', function () {
    it('fills in a proposal for review without saving anything', function () {
        $page = analyzeTrade(stockPurchase($this->fidelity))
            ->assertHasNoErrors()
            ->assertSet('failure', null)
            ->assertSeeLivewire('pages::trade-assistant.stock-review');

        expect($page->get('proposal')['action'])->toBe('buy_stock')
            ->and(StockTrade::count())->toBe(0)
            ->and(OptionPosition::count())->toBe(0);
    });

    it('uses the chosen account when the description does not name one', function () {
        $page = analyzeTrade(stockPurchase($this->fidelity, ['account' => null]), (string) $this->robinhood->id);

        expect($page->get('proposal')['fields']['account'])->toBe(['value' => (string) $this->robinhood->id, 'status' => 'selected']);
    });

    it('asks for one trade at a time when several are described', function () {
        analyzeTrade(tradeAssistantOutput('buy_stock', transactionCount: 2))
            ->assertSee('seems to contain 2 transactions')
            ->assertDontSeeLivewire('pages::trade-assistant.stock-review');
    });

    it('explains when a kind of transaction is not supported yet', function () {
        analyzeTrade(tradeAssistantOutput('not_supported', unsupportedReason: 'A stock split'))
            ->assertSee('A stock split cannot be entered with the assistant')
            ->assertDontSeeLivewire('pages::trade-assistant.sell-review');
    });

    it('explains how to set up the assistant when no AI provider is configured', function () {
        config(['services.ai.provider' => null, 'services.ai.key' => null]);

        Livewire::test('pages::trade-assistant.index')
            ->set('description', 'Buy 100 TSLA at 400')
            ->call('analyze')
            ->assertSet('proposal', null)
            ->assertSee('not set up yet');
    });

    it('shows an AI service problem and fills nothing in', function () {
        fakeTradeAssistant(TradeAssistantException::unreachable());

        Livewire::test('pages::trade-assistant.index')
            ->set('description', 'Buy 100 TSLA at 400')
            ->call('analyze')
            ->assertSet('proposal', null)
            ->assertSee('could not be reached or took too long');
    });

    it('needs a description', function () {
        $extractor = fakeTradeAssistant(stockPurchase($this->fidelity));

        Livewire::test('pages::trade-assistant.index')->call('analyze')->assertHasErrors(['description' => 'required']);

        expect($extractor->calls)->toBe(0);
    });

    it('limits how many trades can be analyzed each minute', function () {
        $extractor = fakeTradeAssistant(stockPurchase($this->fidelity));
        $page = Livewire::test('pages::trade-assistant.index')->set('description', 'Buy 100 TSLA at 400');

        foreach (range(1, 11) as $attempt) {
            $page->call('analyze');
        }

        $page->assertSee('Wait')->assertSet('proposal', null);
        expect($extractor->calls)->toBe(10);

        RateLimiter::clear('trade-assistant:'.$this->user->id);
    });

    it('discards the proposal on cancel and keeps the description', function () {
        analyzeTrade(stockPurchase($this->fidelity))
            ->call('cancel')
            ->assertSet('proposal', null)
            ->assertSet('description', 'A trade described in plain English.')
            ->assertDontSeeLivewire('pages::trade-assistant.stock-review');
    });

    it('does not accept a proposal changed in the browser', function () {
        analyzeTrade(stockPurchase($this->fidelity))->set('proposal', stockPurchase($this->robinhood));
    })->throws(Exception::class);
});

describe('stock review', function () {
    it('records a purchase through the stock trade form', function () {
        reviewPanel(analyzeTrade(stockPurchase($this->fidelity)), 'stock-review')
            ->assertSet('form.side', 'buy')
            ->assertSet('form.ticker', 'TSLA')
            ->assertSee('$40,000.00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('trade-assistant'));

        $trade = StockTrade::sole();

        expect($trade->account_id)->toBe($this->fidelity->id)
            ->and($trade->side)->toBe(TradeSide::Buy)
            ->and($trade->trade_date->format('Y-m-d'))->toBe('2026-06-12')
            ->and($trade->price_per_share->equals(UnitPrice::fromDecimal('400')))->toBeTrue()
            ->and($trade->lot)->not->toBeNull();
    });

    it('records a sale against the shares held', function () {
        recordStockTrade($this->fidelity, TradeSide::Buy, 'TSLA', '2026-03-02', '150', '300');

        reviewPanel(analyzeTrade(tradeAssistantOutput('sell_stock', [
            'account' => (string) $this->fidelity->id, 'symbol' => 'TSLA', 'trade_date' => '2026-06-12', 'quantity' => '100', 'price' => '410',
        ])), 'stock-review')
            ->assertSet('form.side', 'sell')
            ->call('save')
            ->assertHasNoErrors();

        expect(StockTrade::query()->where('side', TradeSide::Sell)->sole()->shares->format())->toBe('100');
    });

    it('saves nothing when a sale needs more shares than were held, and can be corrected', function () {
        recordStockTrade($this->fidelity, TradeSide::Buy, 'TSLA', '2026-03-02', '50', '300');
        $panel = reviewPanel(analyzeTrade(tradeAssistantOutput('sell_stock', [
            'account' => (string) $this->fidelity->id, 'symbol' => 'TSLA', 'trade_date' => '2026-06-12', 'quantity' => '100', 'price' => '410',
        ])), 'stock-review');

        $panel->call('save')->assertHasErrors('form.shares')->assertNoRedirect();
        expect(StockTrade::query()->where('side', TradeSide::Sell)->count())->toBe(0);

        $panel->set('form.shares', '50')->call('save')->assertHasNoErrors();
        expect(StockTrade::query()->where('side', TradeSide::Sell)->count())->toBe(1);
    });

    it('lists missing details, keeps saving disabled, and never guesses them', function () {
        reviewPanel(analyzeTrade(stockPurchase($this->fidelity, ['trade_date' => null, 'quantity' => null])), 'stock-review')
            ->assertSet('form.trade_date', '')
            ->assertSet('form.shares', '')
            ->assertSee('Still needed before saving')
            ->assertSee('Trade date, Shares.')
            ->assertSeeHtml('disabled>Confirm and save')
            ->call('save')
            ->assertHasErrors(['form.trade_date', 'form.shares']);

        expect(StockTrade::count())->toBe(0);
    });

    it('asks about an unclear date instead of filling one in', function () {
        $answer = stockPurchase($this->fidelity, ['trade_date' => null]);
        $answer['fields']['trade_date']['status'] = 'ambiguous';
        $answer['questions'] = ['Which year was June 12?'];

        reviewPanel(analyzeTrade($answer), 'stock-review')
            ->assertSet('form.trade_date', '')
            ->assertSee('Unclear')
            ->assertSee('Which year was June 12?');
    });

    it('shows a date worked out from "today" for confirmation', function () {
        $answer = stockPurchase($this->fidelity, ['trade_date' => '2026-10-27']);
        $answer['fields']['trade_date']['status'] = 'derived';

        reviewPanel(analyzeTrade($answer), 'stock-review')
            ->assertSet('form.trade_date', '2026-10-27')
            ->assertSee('Worked out');
    });

    it('rejects a zero price through the normal form checks', function () {
        reviewPanel(analyzeTrade(stockPurchase($this->fidelity, ['price' => '0'])), 'stock-review')
            ->call('save')
            ->assertHasErrors(['form.price_per_share']);

        expect(StockTrade::count())->toBe(0);
    });

    it('saves a proposal only once, even when the request is repeated', function () {
        $page = analyzeTrade(stockPurchase($this->fidelity));

        reviewPanel($page, 'stock-review')->call('save')->assertHasNoErrors();
        reviewPanel($page, 'stock-review')->call('save')->assertHasErrors('proposal')->assertSee('already been saved');

        expect(StockTrade::count())->toBe(1);
    });
});

describe('sell to open review', function () {
    it('records a sold put and shows the premium before fees', function () {
        reviewPanel(analyzeTrade(soldPut($this->fidelity)), 'sell-review')
            ->assertSee('Proposed put sold to open')
            ->assertSee('$500.00')
            ->call('save')
            ->assertHasNoErrors();

        $position = OptionPosition::sole();

        expect($position->side)->toBe(OptionSide::Short)
            ->and($position->option_type)->toBe(OptionType::Put)
            ->and($position->contracts)->toBe(2)
            ->and($position->multiplier)->toBe(100)
            ->and($position->premium_per_share->equals(UnitPrice::fromDecimal('2.50')))->toBeTrue()
            ->and($position->opening_fees->equals(Money::fromCents(130)))->toBeTrue();
    });

    it('fills in 100 shares per contract when not given, and says so', function () {
        reviewPanel(analyzeTrade(soldPut($this->fidelity, ['multiplier' => null])), 'sell-review')
            ->assertSet('form.multiplier', '100')
            ->assertSee('the standard 100 is filled in');
    });

    it('warns about an expiration on a weekend without changing it', function () {
        reviewPanel(analyzeTrade(soldPut($this->fidelity, ['expiration' => '2027-01-16'])), 'sell-review')
            ->assertSet('form.expires_on', '2027-01-16')
            ->assertSee('Jan 16, 2027 is a Saturday');
    });

    it('warns, without blocking, when the cash does not cover a sold put', function () {
        CashMovement::factory()->for($this->fidelity)->create(['movement_date' => '2026-06-01', 'settlement_date' => '2026-06-01', 'amount' => Money::fromDecimal('10000')]);

        reviewPanel(analyzeTrade(soldPut($this->fidelity)), 'sell-review')
            ->assertSee('could need $80,000.00')
            ->call('save')
            ->assertHasNoErrors();

        expect(OptionPosition::count())->toBe(1);
    });

    it('does not warn when the cash covers the put', function () {
        CashMovement::factory()->for($this->fidelity)->create(['movement_date' => '2026-06-01', 'settlement_date' => '2026-06-01', 'amount' => Money::fromDecimal('100000')]);

        reviewPanel(analyzeTrade(soldPut($this->fidelity)), 'sell-review')->assertDontSee('could need');
    });

    it('records a covered call when enough free shares are held', function () {
        recordStockTrade($this->fidelity, TradeSide::Buy, 'TSLA', '2026-03-02', '200', '300');

        reviewPanel(analyzeTrade(soldPut($this->fidelity, ['option_type' => 'call', 'strike' => '450'])), 'sell-review')
            ->call('save')
            ->assertHasNoErrors();

        expect(OptionPosition::sole()->option_type)->toBe(OptionType::Call);
    });

    it('refuses a covered call that needs more shares than are free', function () {
        recordStockTrade($this->fidelity, TradeSide::Buy, 'TSLA', '2026-03-02', '100', '300');

        reviewPanel(analyzeTrade(soldPut($this->fidelity, ['option_type' => 'call', 'strike' => '450'])), 'sell-review')
            ->call('save')
            ->assertHasErrors('form.contracts');

        expect(OptionPosition::count())->toBe(0);
    });

    it('keeps saving disabled for a call when no shares are held', function () {
        reviewPanel(analyzeTrade(soldPut($this->fidelity, ['option_type' => 'call', 'strike' => '450'])), 'sell-review')
            ->assertSee('No stocks currently have enough available shares')
            ->assertSeeHtml('disabled>Confirm and save');
    });
});

describe('buy to open review', function () {
    it('records a bought call with its cost', function () {
        reviewPanel(analyzeTrade([...soldPut($this->fidelity, ['option_type' => 'call', 'price' => '3.10']), 'action' => 'buy_to_open']), 'buy-review')
            ->assertSee('Proposed call bought to open')
            ->assertSee('$620.00')
            ->call('save')
            ->assertHasNoErrors();

        $position = OptionPosition::sole();

        expect($position->side)->toBe(OptionSide::Long)
            ->and($position->option_type)->toBe(OptionType::Call);
    });
});
