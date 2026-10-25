<?php

use App\Actions\Options\SaveOptionTrade;
use App\Enums\AuditEvent;
use App\Enums\OptionType;
use App\Exceptions\OptionPositionException;
use App\Models\Account;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Support\Money;
use App\Support\UnitPrice;

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->saveOptionTrade = app(SaveOptionTrade::class);
    $this->put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 3, '1.20', '2.01');
});

describe('partial closes', function () {
    it('split the premium and opening fees by contract and leave the rest open', function () {
        $first = recordBuyToClose($this->put, '2026-02-10', 1, '0.50', '0.67');

        expect($first->allocated_premium->format())->toBe('$120.00')
            ->and($first->allocated_opening_fees->format())->toBe('$0.67')
            ->and($first->netProfit()->format())->toBe('$68.66')
            ->and($this->put->openContracts())->toBe(2);

        $second = recordBuyToClose($this->put, '2026-02-17', 2, '0.30', '1.34');

        expect($second->allocated_premium->format())->toBe('$240.00')
            ->and($second->allocated_opening_fees->format())->toBe('$1.34')
            ->and($second->closingCost()->format())->toBe('$60.00')
            ->and($second->netProfit()->format())->toBe('$177.32')
            ->and($this->put->openContracts())->toBe(0);
    });

    it('make the fee pieces add up to the whole opening fee', function () {
        $put = recordSellToOpen($this->account, OptionType::Put, 'ABC', '20', '2026-03-20', '2026-02-02', 3, '0.50', '2.00');

        $first = recordBuyToClose($put, '2026-02-10', 1, '0.10', '0');
        $rest = recordBuyToClose($put, '2026-02-11', 2, '0.10', '0');

        expect($first->allocated_opening_fees->cents)->toBe(67)
            ->and($rest->allocated_opening_fees->cents)->toBe(133);
    });
});

it('uses the stored contract multiplier for premium and closing cost', function () {
    $put = recordSellToOpen($this->account, OptionType::Put, 'ADJ', '50', '2026-03-20', '2026-02-02', 1, '1.20', '0.67', multiplier: 10);

    $close = recordBuyToClose($put, '2026-02-10', 1, '0.40', '0.67');

    expect($put->premiumReceived()->format())->toBe('$12.00')
        ->and($close->closingCost()->format())->toBe('$4.00')
        ->and($close->grossProfit()->format())->toBe('$8.00');
});

describe('refused closes', function () {
    it('closing more contracts than are open saves nothing', function () {
        recordBuyToClose($this->put, '2026-02-10', 2, '0.50', '0');

        expect(fn () => recordBuyToClose($this->put, '2026-02-11', 2, '0.50', '0'))
            ->toThrow(OptionPositionException::class, 'XYZ $50.00 Put, expires Mar 20, 2026 has 3 contracts, but 4 would be closed.');

        expect(OptionEvent::count())->toBe(1);
    });

    it('a close before the option was opened or after it expired', function (string $date) {
        try {
            recordBuyToClose($this->put, $date, 1, '0.50', '0');
            $this->fail('The close should have been refused.');
        } catch (OptionPositionException $exception) {
            expect($exception->field)->toBe('event_date')
                ->and($exception->getMessage())->toContain('is outside the life of');
        }

        expect(OptionEvent::count())->toBe(0);
    })->with(['before opening' => '2026-02-01', 'after expiration' => '2026-03-21']);

    it('a close on the expiration date is allowed', function () {
        expect(recordBuyToClose($this->put, '2026-03-20', 1, '0.05', '0')->exists)->toBeTrue();
    });

    it('closing a voided position', function () {
        $this->saveOptionTrade->voidPosition($this->put, 'Entered by mistake');

        expect(fn () => recordBuyToClose($this->put->fresh(), '2026-02-10', 1, '0.50', '0'))
            ->toThrow(OptionPositionException::class, 'was voided');
    });
});

describe('corrections', function () {
    it('to the premium recalculate closes already recorded', function () {
        $close = recordBuyToClose($this->put, '2026-02-10', 1, '0.40', '0.67');

        $this->saveOptionTrade->updatePosition($this->put, ['premium_per_share' => UnitPrice::fromDecimal('1.50')], 'Premium from confirmation');

        expect($close->fresh()->allocated_premium->format())->toBe('$150.00')
            ->and($this->put->auditEntries()->latest('id')->first())
            ->event->toBe(AuditEvent::Updated)
            ->reason->toBe('Premium from confirmation');
    });

    it('that would leave fewer contracts than already closed are refused', function () {
        recordBuyToClose($this->put, '2026-02-10', 2, '0.40', '0');

        expect(fn () => $this->saveOptionTrade->updatePosition($this->put, ['contracts' => 1], 'Fewer contracts'))
            ->toThrow(OptionPositionException::class, 'but 2 would be closed');

        expect($this->put->fresh()->contracts)->toBe(3);
    });

    it('moving the expiration before a recorded close are refused', function () {
        recordBuyToClose($this->put, '2026-03-10', 1, '0.40', '0');

        expect(fn () => $this->saveOptionTrade->updatePosition($this->put, ['expires_on' => '2026-03-06'], 'Earlier expiry'))
            ->toThrow(OptionPositionException::class);

        expect($this->put->fresh()->expires_on->format('Y-m-d'))->toBe('2026-03-20');
    });

    it('to a close recalculate its profit and are audited', function () {
        $close = recordBuyToClose($this->put, '2026-02-10', 1, '0.40', '0.67');

        $this->saveOptionTrade->updateEvent($close, ['price_per_share' => UnitPrice::fromDecimal('0.30'), 'fees' => Money::fromCents(71)], 'Fill price');

        expect($close->closingCost()->format())->toBe('$30.00')
            ->and($close->netProfit()->format())->toBe('$88.62')
            ->and($close->auditEntries()->latest('id')->first()->reason)->toBe('Fill price');
    });
});

describe('voiding', function () {
    it('a position with recorded closes is refused until the closes are voided', function () {
        $close = recordBuyToClose($this->put, '2026-02-10', 1, '0.40', '0');

        expect(fn () => $this->saveOptionTrade->voidPosition($this->put, 'Mistake'))
            ->toThrow(OptionPositionException::class, 'has recorded closes. Void those first');

        $this->saveOptionTrade->voidEvent($close, 'Mistake');
        $this->saveOptionTrade->voidPosition($this->put->fresh(), 'Mistake');

        expect($this->put->fresh()->isVoided())->toBeTrue();
    });

    it('a close opens its contracts again and recalculates later closes', function () {
        $first = recordBuyToClose($this->put, '2026-02-10', 1, '0.40', '0');
        $second = recordBuyToClose($this->put, '2026-02-11', 2, '0.40', '0');

        expect($second->allocated_opening_fees->cents)->toBe(134);

        $this->saveOptionTrade->voidEvent($first, 'Did not happen');

        expect($this->put->fresh()->openContracts())->toBe(1)
            ->and($second->fresh()->allocated_opening_fees->cents)->toBe(134)
            ->and($first->fresh()->isVoided())->toBeTrue()
            ->and($first->auditEntries()->latest('id')->first()->event)->toBe(AuditEvent::Voided);
    });
});

it('can buy to close an option entered as an opening balance', function () {
    $opening = OptionPosition::factory()->for($this->account)->create([
        'option_type' => OptionType::Put,
        'strike' => UnitPrice::fromDecimal('50'),
        'opened_on' => '2025-12-05',
        'expires_on' => '2026-01-16',
        'contracts' => 1,
        'premium_per_share' => UnitPrice::fromDecimal('1.20'),
        'opening_fees' => Money::fromCents(67),
    ]);

    $close = recordBuyToClose($opening, '2026-01-09', 1, '0.40', '0.67');

    expect($close->netProfit()->format())->toBe('$78.66');
});

it('finds open and closed positions', function () {
    $closed = recordSellToOpen($this->account, OptionType::Call, 'ABC', '25', '2026-03-20', '2026-02-02', 1, '1.00', '0.67');
    recordBuyToClose($closed, '2026-02-10', 1, '0.20', '0.67');

    expect(OptionPosition::query()->open()->pluck('id')->all())->toBe([$this->put->id])
        ->and(OptionPosition::query()->closed()->pluck('id')->all())->toBe([$closed->id]);
});
