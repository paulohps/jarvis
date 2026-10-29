<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. Every seeder is idempotent, so
     * `db:seed` may run again on a seeded database. The notification types
     * come from their classes (`notifications:provision`), after the
     * lookups they point to. The super admin is not seeded: `jarvis:install`
     * asks for them.
     */
    public function run(): void
    {
        $this->call(LookupSeeder::class);

        Artisan::call('notifications:provision');
    }
}
