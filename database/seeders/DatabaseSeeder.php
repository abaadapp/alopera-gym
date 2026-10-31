<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local/demo only. A live installation sets its gym up once with `php artisan gym:install`.
     */
    public function run(): void
    {
        $this->call(AloperaDemoSeeder::class);
    }
}
