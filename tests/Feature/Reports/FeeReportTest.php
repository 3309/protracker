<?php

use App\Enums\OptionType;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\OptionPosition;
use App\Support\FeeReport;
use App\Support\Money;
use App\Support\ReportingPeriod;

beforeEach(function () {
    $this->account = Account::factory()->create(['nickname' => 'Main']);

    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50', '0.65');
    $put = recordSellToOpen($this->account, OptionType::Put, 'ABC', '20', '2026-03-20', '2026-02-02', 1, '0.50', '0.67');
    recordBuyToClose($put, '2026-02-20', 1, '0.10', '0.67');
    OptionPosition::factory()->for($this->account)->create(['opening_fees' => Money::fromCents(500)]);

    $this->fees = FeeReport::for(ReportingPeriod::forYear(2026), null, null);
});

it('totals the fees paid in the period, leaving out fees of opening-balance options paid before it', function () {
    expect($this->fees->total()->format())->toBe('$1.99');
});

it('groups fees by month, type, ticker and account', function () {
    expect($this->fees->byMonth()->map->format()->all())->toBe(['2026-01' => '$0.65', '2026-02' => '$1.34'])
        ->and($this->fees->byType()->map->format()->all())->toBe([FeeReport::OPTION_CLOSING => '$0.67', FeeReport::OPTION_OPENING => '$0.67', FeeReport::STOCK => '$0.65'])
        ->and($this->fees->byTicker()->map->format()->all())->toBe(['ABC' => '$1.34', 'XYZ' => '$0.65'])
        ->and($this->fees->byAccount()->keys()->all())->toBe([$this->account->displayName()]);
});

it('filters by ticker', function () {
    expect(FeeReport::for(ReportingPeriod::forYear(2026), null, 'XYZ')->total()->format())->toBe('$0.65');
});
