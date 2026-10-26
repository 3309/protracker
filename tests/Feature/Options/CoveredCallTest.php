<?php

use App\Actions\Options\EnsureCallsCovered;
use App\Enums\Broker;
use App\Enums\OptionType;
use App\Enums\PositionOrigin;
use App\Exceptions\UncoveredCallException;
use App\Models\Account;
use App\Models\AuditEntry;
use App\Models\OptionPosition;
use App\Models\StockLot;
use App\Models\User;
use App\Support\CoveredCallCapacity;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00'));
    $this->actingAs(User::factory()->create());

    $this->fidelity = Account::factory()->create(['nickname' => 'Taxable', 'broker' => Broker::Fidelity]);
    $this->robinhood = Account::factory()->create(['nickname' => 'Trading', 'broker' => Broker::Robinhood]);

    StockLot::factory()->for($this->fidelity)->create(['ticker' => 'MSTR', 'acquired_on' => '2025-12-01', 'shares' => Quantity::fromInteger(300), 'cost_per_share' => UnitPrice::fromDecimal('445.34')]);
    StockLot::factory()->for($this->robinhood)->create(['ticker' => 'MSTR', 'acquired_on' => '2025-12-01', 'shares' => Quantity::fromInteger(200), 'cost_per_share' => UnitPrice::fromDecimal('300')]);

    $this->today = CarbonImmutable::parse('2026-10-25');
    $this->maxContracts = fn (Account $account, string $ticker = 'MSTR') => CoveredCallCapacity::for($account->id, $ticker, $this->today)->maxContracts(100);
});

/**
 * An MSTR call already sold in the account, recorded without the form.
 */
function openMstrCall(Account $account, int $contracts, string $openedOn = '2026-10-01', string $expiresOn = '2026-11-20'): OptionPosition
{
    return recordSellToOpen($account, OptionType::Call, 'MSTR', '500', $expiresOn, $openedOn, $contracts, '5.00', '0.65');
}

function sellCallPage(Account $account): Testable
{
    return Livewire::test('pages::options.create')
        ->set('form.account_id', (string) $account->id)
        ->set('form.option_type', 'call');
}

function sellCallWithForm(Account $account, string $ticker, string $contracts, string $openedOn = '2026-10-23'): Testable
{
    return sellCallPage($account)
        ->set('form.underlying', $ticker)
        ->set('form.strike', '500')
        ->set('form.expires_on', '2026-11-20')
        ->set('form.opened_on', $openedOn)
        ->set('form.contracts', $contracts)
        ->set('form.premium_per_share', '5.00')
        ->call('save');
}

describe('shares available', function () {
    it('allows one contract per 100 shares not already covering an open call', function (int $openContracts, int $expected) {
        if ($openContracts > 0) {
            openMstrCall($this->fidelity, $openContracts);
        }

        expect(($this->maxContracts)($this->fidelity))->toBe($expected);
    })->with([
        'no open calls' => [0, 3],
        'one open contract' => [1, 2],
        'two open contracts' => [2, 1],
        'three open contracts' => [3, 0],
    ]);

    it('counts each account separately', function () {
        openMstrCall($this->fidelity, 3);

        expect(($this->maxContracts)($this->fidelity))->toBe(0)
            ->and(($this->maxContracts)($this->robinhood))->toBe(2);
    });

    it('rounds down to whole contracts', function () {
        StockLot::factory()->for($this->fidelity)->create(['ticker' => 'COIN', 'acquired_on' => '2025-12-01', 'shares' => Quantity::fromDecimal('250.5')]);

        expect(($this->maxContracts)($this->fidelity, 'COIN'))->toBe(2);
    });

    it('releases only the contracts bought back', function () {
        $call = openMstrCall($this->fidelity, 3);
        recordBuyToClose($call, '2026-10-20', 1, '1.00', '0.65');

        expect(($this->maxContracts)($this->fidelity))->toBe(1);
    });

    it('releases the shares when the call expires', function () {
        $call = openMstrCall($this->fidelity, 2, '2026-09-01', '2026-10-16');

        expect(($this->maxContracts)($this->fidelity))->toBe(1);

        recordExpiry($call, '2026-10-16', 2);

        expect(($this->maxContracts)($this->fidelity))->toBe(3);
    });

    it('removes assigned shares from the holdings instead of freeing them', function () {
        $call = openMstrCall($this->fidelity, 3, '2026-09-01', '2026-10-16');
        recordAssignment($call, '2026-10-16', 1);

        $capacity = CoveredCallCapacity::for($this->fidelity->id, 'MSTR', $this->today);

        expect($capacity->held->format())->toBe('200')
            ->and($capacity->committed()->format())->toBe('200')
            ->and($capacity->maxContracts(100))->toBe(0);
    });

    it('frees every share once all contracts are closed', function () {
        $call = openMstrCall($this->fidelity, 3);
        recordBuyToClose($call, '2026-10-20', 3, '1.00', '1.95');

        expect(($this->maxContracts)($this->fidelity))->toBe(3);
    });

    it('keeps reserving shares for a call past expiration with nothing recorded, and says so', function () {
        openMstrCall($this->fidelity, 1, '2026-09-01', '2026-10-16');

        expect(($this->maxContracts)($this->fidelity))->toBe(2);

        sellCallPage($this->fidelity)
            ->set('form.underlying', 'MSTR')
            ->assertSee('has passed its expiration with no expiry, assignment or buy-back recorded');
    });

    it('ignores puts and bought calls', function () {
        recordSellToOpen($this->fidelity, OptionType::Put, 'MSTR', '400', '2026-11-20', '2026-10-01', 5, '5.00', '3.25');
        recordBuyToOpen($this->fidelity, OptionType::Call, 'MSTR', '600', '2026-11-20', '2026-10-01', 2, '5.00', '1.30');

        expect(($this->maxContracts)($this->fidelity))->toBe(3);
    });
});

describe('sell call form', function () {
    it('lists only stocks with free shares in the chosen account, with the shares available', function () {
        StockLot::factory()->for($this->fidelity)->create(['ticker' => 'COIN', 'acquired_on' => '2025-12-01', 'shares' => Quantity::fromInteger(100)]);
        StockLot::factory()->for($this->fidelity)->create(['ticker' => 'ULTY', 'acquired_on' => '2025-12-01', 'shares' => Quantity::fromInteger(10)]);

        sellCallPage($this->fidelity)
            ->assertSee('MSTR — 300 shares available (up to 3 contracts)')
            ->assertSee('COIN — 100 shares available (up to 1 contract)')
            ->assertDontSee('ULTY —')
            ->set('form.account_id', (string) $this->robinhood->id)
            ->assertSee('MSTR — 200 shares available (up to 2 contracts)')
            ->assertDontSee('COIN —');
    });

    it('shows the availability summary and offers up to the maximum contracts', function () {
        openMstrCall($this->fidelity, 2);

        sellCallPage($this->fidelity)
            ->set('form.underlying', 'MSTR')
            ->assertSeeInOrder(['Eligible shares held', '300', 'Shares committed to open calls', '200', 'Shares available', '100', 'Maximum additional covered calls', '1 contract'])
            ->assertSee('Up to 1 contract can be covered by the free shares.');
    });

    it('does not offer a stock whose shares all cover open calls, and turns off saving', function () {
        openMstrCall($this->fidelity, 3);

        sellCallPage($this->fidelity)
            ->assertDontSee('MSTR —')
            ->assertSee('No stocks currently have enough available shares for a covered call in this account.')
            ->assertSee(route('positions', ['account' => $this->fidelity->id]))
            ->assertSeeHtml('disabled>Record sale');
    });

    it('records a covered call', function () {
        sellCallWithForm($this->fidelity, 'MSTR', '3')->assertHasNoErrors();

        expect(OptionPosition::sole())->contracts->toBe(3)->account_id->toBe($this->fidelity->id)
            ->and(($this->maxContracts)($this->fidelity))->toBe(0);
    });

    it('rejects more contracts than the free shares cover, saving nothing', function () {
        openMstrCall($this->fidelity, 2);
        $optionHistory = fn () => AuditEntry::query()->where('auditable_type', (new OptionPosition)->getMorphClass())->count();
        $historyBefore = $optionHistory();

        sellCallWithForm($this->fidelity, 'MSTR', '2')
            ->assertHasErrors('form.contracts')
            ->assertSee('Only 100 MSTR shares are free in Fidelity · Taxable on Oct 23, 2026, enough for 1 more contract. You asked for 2.');

        expect(OptionPosition::count())->toBe(1)
            ->and($optionHistory())->toBe($historyBefore);
    });

    it('rejects shares from another account', function () {
        openMstrCall($this->fidelity, 3);

        sellCallWithForm($this->fidelity, 'MSTR', '1')
            ->assertHasErrors('form.contracts')
            ->assertSee('No MSTR shares are free to cover a call');

        sellCallWithForm($this->robinhood, 'MSTR', '2')->assertHasNoErrors();
    });

    it('rejects a ticker typed in that the account does not hold', function () {
        sellCallWithForm($this->fidelity, 'TSLA', '1')->assertHasErrors('form.contracts');

        expect(OptionPosition::count())->toBe(0);
    });

    it('counts the shares on the trade date', function () {
        StockLot::factory()->for($this->fidelity)->create(['ticker' => 'TSLA', 'origin' => PositionOrigin::Purchase, 'acquired_on' => '2026-10-20', 'shares' => Quantity::fromInteger(100)]);

        sellCallWithForm($this->fidelity, 'TSLA', '1', '2026-10-19')->assertHasErrors('form.contracts');
        sellCallWithForm($this->fidelity, 'TSLA', '1', '2026-10-20')->assertHasNoErrors();
    });

    it('rejects a backdated call that would leave a later call uncovered', function () {
        openMstrCall($this->fidelity, 3, '2026-10-10');

        sellCallWithForm($this->fidelity, 'MSTR', '1', '2026-10-05')
            ->assertHasErrors('form.contracts')
            ->assertSee('on Oct 10, 2026 the open calls would need 400 shares, but 300 are held.');

        expect(OptionPosition::count())->toBe(1);
    });

    it('leaves selling puts unchanged', function () {
        sellCallPage($this->fidelity)
            ->set('form.option_type', 'put')
            ->set('form.underlying', 'NVDA')
            ->set('form.strike', '150')
            ->set('form.expires_on', '2026-11-20')
            ->set('form.opened_on', '2026-10-23')
            ->set('form.contracts', '5')
            ->set('form.premium_per_share', '2.00')
            ->call('save')
            ->assertHasNoErrors();

        expect(OptionPosition::sole()->option_type)->toBe(OptionType::Put);
    });
});

describe('server-side protection', function () {
    it('rolls back a sale when the shares are used up between the form check and saving', function () {
        openMstrCall($this->fidelity, 2);

        expect(fn () => app(EnsureCallsCovered::class)->afterChange(
            $this->fidelity->id,
            'MSTR',
            CarbonImmutable::parse('2026-10-23'),
            CarbonImmutable::parse('2026-11-20'),
            function () {
                openMstrCall($this->fidelity, 1, '2026-10-23');

                return openMstrCall($this->fidelity, 1, '2026-10-23');
            },
        ))->toThrow(UncoveredCallException::class);

        expect(OptionPosition::count())->toBe(1)
            ->and(($this->maxContracts)($this->fidelity))->toBe(1);
    });

    it('lets a second sale of the same shares fail after the first succeeds', function () {
        $secondTab = sellCallPage($this->fidelity);

        sellCallWithForm($this->fidelity, 'MSTR', '3')->assertHasNoErrors();

        $secondTab->set('form.underlying', 'MSTR')
            ->set('form.strike', '520')
            ->set('form.expires_on', '2026-11-20')
            ->set('form.opened_on', '2026-10-23')
            ->set('form.contracts', '1')
            ->set('form.premium_per_share', '4.00')
            ->call('save')
            ->assertHasErrors('form.contracts');

        expect(OptionPosition::count())->toBe(1);
    });

    it('checks a roll of a call', function () {
        $call = openMstrCall($this->fidelity, 3);

        Livewire::test('pages::options.show', ['optionPosition' => $call])
            ->call('chooseAction', 'roll')
            ->set('roll.event_date', '2026-10-23')
            ->set('roll.contracts', '1')
            ->set('roll.price_per_share', '1.50')
            ->set('roll.new_expires_on', '2026-12-18')
            ->set('roll.new_contracts', '2')
            ->set('roll.new_premium_per_share', '6.00')
            ->call('recordRoll')
            ->assertHasErrors('roll.new_contracts');

        expect(OptionPosition::count())->toBe(1)
            ->and($call->fresh()->events()->count())->toBe(0);

        Livewire::test('pages::options.show', ['optionPosition' => $call])
            ->call('chooseAction', 'roll')
            ->set('roll.event_date', '2026-10-23')
            ->set('roll.contracts', '1')
            ->set('roll.price_per_share', '1.50')
            ->set('roll.new_expires_on', '2026-12-18')
            ->set('roll.new_contracts', '1')
            ->set('roll.new_premium_per_share', '6.00')
            ->call('recordRoll')
            ->assertHasNoErrors();
    });

    it('checks a correction that adds contracts to a call, but not one that only fixes the premium', function () {
        $uncovered = recordSellToOpen($this->fidelity, OptionType::Call, 'AMD', '200', '2026-11-20', '2026-10-01', 1, '3.00', '0.65');
        $call = openMstrCall($this->fidelity, 3);

        Livewire::test('pages::options.edit', ['optionPosition' => $call])
            ->set('form.contracts', '4')
            ->set('form.reason', 'Typo')
            ->call('save')
            ->assertHasErrors('form.contracts');

        expect($call->fresh()->contracts)->toBe(3);

        Livewire::test('pages::options.edit', ['optionPosition' => $uncovered])
            ->set('form.premium_per_share', '3.10')
            ->set('form.reason', 'Statement shows 3.10')
            ->call('save')
            ->assertHasNoErrors();
    });
});
