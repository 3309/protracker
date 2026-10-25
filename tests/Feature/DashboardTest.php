<?php

use App\Models\Account;
use App\Models\User;

it('shows the number of active accounts and the reporting start date', function () {
    Account::factory()->count(2)->create();
    Account::factory()->archived()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('You have 2 active accounts.')
        ->assertSeeText('Reporting starts on January 1, 2026.');
});

it('invites the user to add an account when there are none', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee('adding your first brokerage account');
});
