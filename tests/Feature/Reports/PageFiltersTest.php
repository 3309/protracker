<?php

use App\Enums\Broker;
use App\Enums\OptionType;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\Dividend;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $taxable = Account::factory()->create(['nickname' => 'Taxable', 'broker' => Broker::Fidelity]);
    $ira = Account::factory()->create(['nickname' => 'IRA', 'broker' => Broker::Robinhood]);

    foreach ([[$taxable, 'XYZ'], [$ira, 'ABC']] as [$account, $ticker]) {
        recordStockTrade($account, TradeSide::Buy, $ticker, '2026-01-12', '100', '50');
        recordStockTrade($account, TradeSide::Sell, $ticker, '2026-03-02', '50', '52');
        recordSellToOpen($account, OptionType::Call, $ticker, '55', '2026-12-18', '2026-02-02', 1, '1.20', '0.67');
        Dividend::factory()->for($account)->create(['ticker' => $ticker, 'ex_date' => '2026-03-10', 'pay_date' => '2026-03-17']);
    }
});

it('filters every list page by ticker and by broker', function (string $page, array $filter) {
    Livewire::withQueryParams(['year' => '2026', 'status' => 'all', ...$filter])
        ->test($page)
        ->assertSee('ABC')
        ->assertDontSee('XYZ');
})->with([
    'stock trades' => 'pages::stock-trades.index',
    'positions' => 'pages::positions',
    'options' => 'pages::options.index',
    'dividends' => 'pages::dividends.index',
    'realized P/L' => 'pages::realized.index',
])->with([
    'ticker' => [['ticker' => 'abc']],
    'broker' => [['broker' => 'robinhood']],
]);

it('filters the cash ledger by broker and offers no ticker filter there', function () {
    Livewire::withQueryParams(['year' => '2026', 'broker' => 'robinhood'])
        ->test('pages::cash.index')
        ->assertSee('Bought 100 ABC')
        ->assertDontSee('Bought 100 XYZ')
        ->assertSee('Broker')
        ->assertDontSeeHtml('id="filter_ticker"');
});
