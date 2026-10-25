<?php

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

    Route::livewire('/positions', 'pages::positions')->name('positions');
    Route::livewire('/realized/stocks', 'pages::realized.stocks')->name('realized.stocks');

    Route::livewire('/settings', 'pages::settings.general')->name('settings');
    Route::view('/settings/password', 'settings.password')->name('settings.password');
});
