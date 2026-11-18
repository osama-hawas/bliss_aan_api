<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * First dashboard super admin on a server. Not part of DatabaseSeeder: run it on purpose with
 * `php artisan db:seed --class=AdminSeeder --force`. Phone and name come from ADMIN_PHONE / ADMIN_NAME in .env when
 * set. Reuses `admin:create`, so it is idempotent and refuses a phone that already belongs to a client or provider.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $exitCode = Artisan::call('admin:create', [
            'phone' => env('ADMIN_PHONE', '0500000009'),
            'name' => env('ADMIN_NAME', 'مدير النظام'),
        ]);

        $this->command->getOutput()->write(Artisan::output());

        if ($exitCode !== 0) {
            throw new \RuntimeException('AdminSeeder failed: see the message above.');
        }
    }
}
