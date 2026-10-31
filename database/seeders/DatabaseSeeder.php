<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Nothing is created by default: a real installation starts empty, the login is made with
     * `php artisan app:create-user`, and fictional demo data is loaded only on request with
     * `php artisan db:seed --class=DemoDataSeeder`.
     */
    public function run(): void
    {
        $this->command?->info('Nothing to seed. Create your login with "php artisan app:create-user", or load fictional demo data with "php artisan db:seed --class=DemoDataSeeder".');
    }
}
