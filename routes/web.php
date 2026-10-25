<?php

use App\Http\Controllers\ImportTemplateController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('auth')->group(function () {
    Route::livewire('/dashboard', 'pages::dashboard')->name('dashboard');

    Route::livewire('/accounts', 'pages::accounts.index')->name('accounts.index');
    Route::livewire('/accounts/create', 'pages::accounts.create')->name('accounts.create');
    Route::livewire('/accounts/{account}/edit', 'pages::accounts.edit')->name('accounts.edit');

    Route::livewire('/opening-balances', 'pages::opening-balances.index')->name('opening-balances.index');
    Route::livewire('/opening-balances/{account}', 'pages::opening-balances.show')->name('opening-balances.show');
    Route::livewire('/opening-balances/{account}/stock-lots/create', 'pages::opening-balances.stock-lots.create')->name('opening-balances.stock-lots.create');
    Route::livewire('/opening-balances/stock-lots/{stockLot}/edit', 'pages::opening-balances.stock-lots.edit')->name('opening-balances.stock-lots.edit');
    Route::livewire('/opening-balances/{account}/options/create', 'pages::opening-balances.options.create')->name('opening-balances.options.create');
    Route::livewire('/opening-balances/options/{optionPosition}/edit', 'pages::opening-balances.options.edit')->name('opening-balances.options.edit');

    Route::livewire('/stock-trades', 'pages::stock-trades.index')->name('stock-trades.index');
    Route::livewire('/stock-trades/create', 'pages::stock-trades.create')->name('stock-trades.create');
    Route::livewire('/stock-trades/{stockTrade}/edit', 'pages::stock-trades.edit')->name('stock-trades.edit');

    Route::livewire('/options', 'pages::options.index')->name('options.index');
    Route::livewire('/options/create', 'pages::options.create')->name('options.create');
    Route::livewire('/options/buy', 'pages::options.buy')->name('options.buy');
    Route::livewire('/options/{optionPosition}', 'pages::options.show')->name('options.show');
    Route::livewire('/options/{optionPosition}/edit', 'pages::options.edit')->name('options.edit');
    Route::livewire('/options/{optionPosition}/chain', 'pages::options.chain')->name('options.chain');
    Route::livewire('/option-events/{optionEvent}/edit', 'pages::options.events.edit')->name('options.events.edit');

    Route::livewire('/dividends', 'pages::dividends.index')->name('dividends.index');
    Route::livewire('/dividends/create', 'pages::dividends.create')->name('dividends.create');
    Route::livewire('/dividends/{dividend}/edit', 'pages::dividends.edit')->name('dividends.edit');

    Route::livewire('/cash', 'pages::cash.index')->name('cash.index');
    Route::livewire('/cash/movements/create', 'pages::cash.movements.create')->name('cash.movements.create');
    Route::livewire('/cash/movements/{cashMovement}/edit', 'pages::cash.movements.edit')->name('cash.movements.edit');
    Route::livewire('/cash/reconciliation', 'pages::cash.reconciliation')->name('cash.reconciliation');
    Route::livewire('/cash/broker-balances/{brokerBalance}/edit', 'pages::cash.broker-balances.edit')->name('cash.broker-balances.edit');

    Route::livewire('/prices', 'pages::prices.index')->name('prices.index');
    Route::livewire('/prices/{price}', 'pages::prices.edit')->name('prices.edit');

    Route::livewire('/imports', 'pages::imports.index')->name('imports.index');
    Route::livewire('/imports/create', 'pages::imports.create')->name('imports.create');
    Route::get('/imports/template', ImportTemplateController::class)->name('imports.template');
    Route::livewire('/imports/review', 'pages::imports.review')->name('imports.review');
    Route::livewire('/imports/{importBatch}', 'pages::imports.show')->name('imports.show');

    Route::livewire('/positions', 'pages::positions')->name('positions');
    Route::livewire('/realized', 'pages::realized.index')->name('realized.index');

    Route::livewire('/settings', 'pages::settings.general')->name('settings');
    Route::view('/settings/password', 'settings.password')->name('settings.password');
});
