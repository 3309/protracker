<?php

namespace App\Console\Commands;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Signature('app:reset-password {email : The email address of the user}')]
#[Description('Set a new password for a user (used instead of password-reset emails)')]
class ResetPassword extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ResetUserPassword $resetUserPassword): int
    {
        $user = User::query()->where('email', Str::lower(trim((string) $this->argument('email'))))->first();

        if ($user === null) {
            $this->error('No user has that email address.');

            return self::FAILURE;
        }

        try {
            $resetUserPassword->reset($user, [
                'password' => (string) $this->secret('New password (at least 12 characters)'),
                'password_confirmation' => (string) $this->secret('Confirm new password'),
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $this->info("The password for {$user->email} has been changed.");

        return self::SUCCESS;
    }
}
