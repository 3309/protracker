<?php

namespace App\Console\Commands;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Signature('app:create-user')]
#[Description('Create the login for the single user of this application')]
class CreateUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CreateNewUser $createNewUser): int
    {
        if (User::query()->exists()) {
            $this->error('A user already exists. This application supports one user. Use app:reset-password to change the password.');

            return self::FAILURE;
        }

        try {
            $user = $createNewUser->create([
                'name' => (string) $this->ask('Name'),
                'email' => Str::lower(trim((string) $this->ask('Email address'))),
                'password' => (string) $this->secret('Password (at least 12 characters)'),
                'password_confirmation' => (string) $this->secret('Confirm password'),
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $this->info("User {$user->email} created. You can now log in.");

        return self::SUCCESS;
    }
}
