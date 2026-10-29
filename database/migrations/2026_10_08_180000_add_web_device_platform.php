<?php

use App\Support\LookupCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The browser as a device platform (`web`), so the web app installed as a
 * PWA registers for push like a phone does. The row is upserted here for
 * the databases already migrated; LookupSeeder keeps it from then on.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        DB::table('device_platforms')->updateOrInsert(
            ['slug' => 'web'],
            fn (bool $exists): array => [
                'name' => 'Browser',
                ...($exists ? [] : ['created_at' => $now]),
                'updated_at' => $now,
            ],
        );

        app(LookupCache::class)->flush();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('devices')
            ->whereIn('device_platform_id', DB::table('device_platforms')->where('slug', 'web')->pluck('id'))
            ->delete();

        DB::table('device_platforms')->where('slug', 'web')->delete();

        app(LookupCache::class)->flush();
    }
};
