<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('renders the change password page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.password'))
        ->assertOk()
        ->assertSee('Change password');
});

it('changes the password when the current password is correct', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('settings.password'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'a-new-long-password',
            'password_confirmation' => 'a-new-long-password',
        ])
        ->assertRedirect(route('settings.password'))
        ->assertSessionHasNoErrors();

    expect(Hash::check('a-new-long-password', $user->refresh()->password))->toBeTrue();
});

it('rejects the change when the current password is wrong', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('settings.password'))
        ->put(route('user-password.update'), [
            'current_password' => 'not-my-password',
            'password' => 'a-new-long-password',
            'password_confirmation' => 'a-new-long-password',
        ])
        ->assertSessionHasErrorsIn('updatePassword', [
            'current_password' => 'The provided password does not match your current password.',
        ]);

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

it('requires a new password of at least 12 characters', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('settings.password'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'short-pass',
            'password_confirmation' => 'short-pass',
        ])
        ->assertSessionHasErrorsIn('updatePassword', [
            'password' => 'The password field must be at least 12 characters.',
        ]);
});
