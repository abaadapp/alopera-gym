<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local/demo only. Production gets its platform admin from
     * `php artisan platform:create-admin` and clubs from the Super Admin panel.
     */
    public function run(): void
    {
        $this->call(AloperaDemoSeeder::class);
    }
}
