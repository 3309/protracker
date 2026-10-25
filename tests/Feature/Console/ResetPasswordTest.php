<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('sets a new password for the user with that email', function () {
    $user = User::factory()->create(['email' => 'pat@example.com']);

    $this->artisan('app:reset-password', ['email' => 'PAT@example.com'])
        ->expectsQuestion('New password (at least 12 characters)', 'a-new-long-password')
        ->expectsQuestion('Confirm new password', 'a-new-long-password')
        ->expectsOutput('The password for pat@example.com has been changed.')
        ->assertSuccessful();

    expect(Hash::check('a-new-long-password', $user->refresh()->password))->toBeTrue();
});

it('fails when no user has that email', function () {
    $this->artisan('app:reset-password', ['email' => 'nobody@example.com'])
        ->expectsOutput('No user has that email address.')
        ->assertFailed();
});

it('keeps the old password when the confirmation does not match', function () {
    $user = User::factory()->create();

    $this->artisan('app:reset-password', ['email' => $user->email])
        ->expectsQuestion('New password (at least 12 characters)', 'a-new-long-password')
        ->expectsQuestion('Confirm new password', 'something-else-entirely')
        ->expectsOutput('The password field confirmation does not match.')
        ->assertFailed();

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});
