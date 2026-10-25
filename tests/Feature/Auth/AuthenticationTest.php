<?php

use App\Models\Account;
use App\Models\User;

it('redirects guests to the login page', function (string $url) {
    $this->get($url)->assertRedirect(route('login'));
})->with([
    'dashboard' => '/dashboard',
    'accounts list' => '/accounts',
    'add account' => '/accounts/create',
    'settings' => '/settings',
    'change password' => '/settings/password',
]);

it('redirects guests away from the account edit page', function () {
    $account = Account::factory()->create();

    $this->get(route('accounts.edit', $account))->assertRedirect(route('login'));
});

it('renders the login page', function () {
    $this->get(route('login'))->assertOk()->assertSee('Log in');
});

it('logs in with the correct email and password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('rejects an incorrect password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

    $this->assertGuest();
});

it('blocks login attempts after five failures in a minute', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertTooManyRequests();

    $this->assertGuest();
});

it('logs the user out', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect('/');

    $this->assertGuest();
});

it('does not offer public registration or emailed password resets', function (string $url) {
    $this->get($url)->assertNotFound();
})->with([
    'registration' => '/register',
    'forgot password' => '/forgot-password',
    'reset password' => '/reset-password/some-token',
]);
