<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('auth')->group(function () {
    Route::livewire('/dashboard', 'pages::dashboard')->name('dashboard');

    Route::livewire('/accounts', 'pages::accounts.index')->name('accounts.index');
    Route::livewire('/accounts/create', 'pages::accounts.create')->name('accounts.create');
    Route::livewire('/accounts/{account}/edit', 'pages::accounts.edit')->name('accounts.edit');

    Route::livewire('/settings', 'pages::settings.general')->name('settings');
    Route::view('/settings/password', 'settings.password')->name('settings.password');
});
