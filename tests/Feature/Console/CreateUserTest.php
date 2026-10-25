<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates the single user with a lowercased email', function () {
    $this->artisan('app:create-user')
        ->expectsQuestion('Name', 'Pat Owner')
        ->expectsQuestion('Email address', 'Pat@Example.com')
        ->expectsQuestion('Password (at least 12 characters)', 'a-long-password')
        ->expectsQuestion('Confirm password', 'a-long-password')
        ->expectsOutput('User pat@example.com created. You can now log in.')
        ->assertSuccessful();

    $user = User::sole();

    expect($user->name)->toBe('Pat Owner')
        ->and($user->email)->toBe('pat@example.com')
        ->and(Hash::check('a-long-password', $user->password))->toBeTrue();
});

it('refuses to create a second user', function () {
    User::factory()->create();

    $this->artisan('app:create-user')
        ->expectsOutput('A user already exists. This application supports one user. Use app:reset-password to change the password.')
        ->assertFailed();

    expect(User::count())->toBe(1);
});

it('reports validation errors without creating a user', function () {
    $this->artisan('app:create-user')
        ->expectsQuestion('Name', 'Pat Owner')
        ->expectsQuestion('Email address', 'pat@example.com')
        ->expectsQuestion('Password (at least 12 characters)', 'short')
        ->expectsQuestion('Confirm password', 'short')
        ->expectsOutput('The password field must be at least 12 characters.')
        ->assertFailed();

    expect(User::count())->toBe(0);
});
