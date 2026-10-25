<?php

use App\Enums\OptionType;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\AccountOpening;
use App\Models\BrokerBalance;
use App\Models\CashMovement;
use App\Models\Dividend;
use App\Models\OptionPosition;
use App\Models\Price;
use App\Models\StockLot;
use App\Models\User;
use App\Support\Money;
use App\Support\UnitPrice;

it('opens every page with records in place: gains, losses, open and closed options, and a negative balance', function () {
    $this->travelTo('2026-03-31 18:00:00');
    $this->actingAs(User::factory()->create());

    $account = Account::factory()->create(['nickname' => 'Main']);
    AccountOpening::factory()->complete()->for($account)->create(['cash_balance' => Money::fromDecimal('1000')]);
    $openingLot = StockLot::factory()->for($account)->create(['ticker' => 'OLD']);
    $openingOption = OptionPosition::factory()->for($account)->create(['underlying' => 'OLD']);

    $buy = recordStockTrade($account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    $sale = recordStockTrade($account, TradeSide::Sell, 'XYZ', '2026-02-12', '50', '45');
    $put = recordSellToOpen($account, OptionType::Put, 'XYZ', '45', '2026-04-02', '2026-03-02', 2, '1.20', '1.34');
    $closedCall = recordBuyToOpen($account, OptionType::Call, 'XYZ', '55', '2026-03-20', '2026-03-02', 1, '2.00', '0.67');
    $sellToClose = recordSellToClose($closedCall, '2026-03-10', 1, '3.00', '0.67');
    Price::factory()->create(['ticker' => 'XYZ', 'price' => UnitPrice::fromDecimal('44'), 'as_of' => '2026-03-31 20:00:00']);
    $price = Price::factory()->option(OptionType::Put, '45', '2026-04-02')->create(['ticker' => 'XYZ', 'price' => UnitPrice::fromDecimal('1.50'), 'as_of' => '2026-03-31 20:00:00']);
    $dividend = Dividend::factory()->for($account)->create(['ticker' => 'XYZ', 'ex_date' => '2026-02-01', 'pay_date' => '2026-02-15']);
    $movement = CashMovement::factory()->for($account)->create();
    $brokerBalance = BrokerBalance::factory()->for($account)->create(['as_of_date' => '2026-03-31', 'total_cash' => Money::fromDecimal('-1')]);
    $batch = previewTemplateCsv($account, ['2026-03-05,BUY,ABC,10,20.00,0,-200.00,,,,,,,,']);

    $pages = [
        route('dashboard'), route('positions'), route('realized.index'), route('settings'), route('settings.password'),
        route('accounts.index'), route('accounts.create'), route('accounts.edit', $account),
        route('opening-balances.index'), route('opening-balances.show', $account),
        route('opening-balances.stock-lots.create', $account), route('opening-balances.stock-lots.edit', $openingLot),
        route('opening-balances.options.create', $account), route('opening-balances.options.edit', $openingOption),
        route('stock-trades.index'), route('stock-trades.create'), route('stock-trades.edit', $buy), route('stock-trades.edit', $sale),
        route('options.index'), route('options.index', ['status' => 'closed']), route('options.create'), route('options.buy'),
        route('options.show', $put), route('options.show', $closedCall), route('options.edit', $put), route('options.edit', $closedCall),
        route('options.events.edit', $sellToClose),
        route('dividends.index'), route('dividends.create'), route('dividends.edit', $dividend),
        route('cash.index'), route('cash.movements.create'), route('cash.movements.edit', $movement),
        route('cash.reconciliation'), route('cash.broker-balances.edit', $brokerBalance),
        route('prices.index'), route('prices.edit', $price),
        route('imports.index'), route('imports.create'), route('imports.review'), route('imports.show', $batch),
    ];

    foreach ($pages as $url) {
        expect($this->get($url)->status())->toBe(200, "{$url} did not open");
    }

    $this->get(route('options.index', ['status' => 'all']))->assertOk()->assertSee('+$98.66');
});
