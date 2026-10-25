<?php

use App\Enums\OptionType;
use App\Models\Account;

beforeEach(function () {
    $this->account = Account::factory()->create();
});

it('Example B: buying a put back to close gives a net realized profit of $78.66', function () {
    $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 1, '1.20', '0.67');

    $close = recordBuyToClose($put, '2026-02-20', 1, '0.40', '0.67');

    expect($put->premiumReceived()->format())->toBe('$120.00')
        ->and($close->allocated_premium->format())->toBe('$120.00')
        ->and($close->closingCost()->format())->toBe('$40.00')
        ->and($close->grossProfit()->format())->toBe('$80.00')
        ->and($close->totalFees()->format())->toBe('$1.34')
        ->and($close->netProfit()->format())->toBe('$78.66')
        ->and($put->fresh()->isOpen())->toBeFalse();
});

it('Example B override: a $0.71 closing fee gives a net realized profit of $78.62', function () {
    $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 1, '1.20', '0.67');

    $close = recordBuyToClose($put, '2026-02-20', 1, '0.40', '0.71');

    expect($close->totalFees()->format())->toBe('$1.38')
        ->and($close->netProfit()->format())->toBe('$78.62');
});

it('Example A fees: the put and the call each open with the right gross premium and a $0.67 fee', function () {
    $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-02-20', '2026-01-12', 1, '1.20', '0.67');
    $call = recordSellToOpen($this->account, OptionType::Call, 'XYZ', '52', '2026-03-20', '2026-02-23', 1, '0.80', '0.67');

    expect($put->premiumReceived()->format())->toBe('$120.00')
        ->and($put->opening_fees->format())->toBe('$0.67')
        ->and($call->premiumReceived()->format())->toBe('$80.00')
        ->and($call->opening_fees->format())->toBe('$0.67')
        ->and($put->opening_fees->plus($call->opening_fees)->format())->toBe('$1.34');
});
