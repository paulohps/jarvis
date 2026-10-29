<?php

use App\Actions\Whatsapp\EnsureWhatsappConversation;
use App\Models\ChatProvider;
use App\Models\ChatTag;
use App\Models\WhatsappTag;
use App\Models\WhatsappTarget;
use App\Support\LookupCache;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WhatsApp joins the chat layer: every chat already known (a target with a
 * message either way, or pinned) gets its entry on the unified chat screen
 * (EnsureWhatsappConversation, which the WhatsappTargetObserver keeps from
 * here on), the WhatsApp tags are copied to the chat layer's own by name
 * (the ones already there are kept) and put on the entries of the chats
 * they were on, and the `whatsapp` chat provider is switched on (the
 * provider rows are written here when the seeder has not run yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        // The seeder runs after the migrations: the providers are written here
        // first (the same rows, the seeder then updates them) so the entries
        // below have a provider to point at on a database that has chats already.
        foreach ([[ChatProvider::WHATSAPP, 'WhatsApp', 1], [ChatProvider::SLACK, 'Slack', 2]] as [$slug, $name, $sortOrder]) {
            DB::table('chat_providers')->updateOrInsert(
                ['slug' => $slug],
                fn (bool $exists): array => [
                    'name' => $name,
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                    ...($exists ? [] : ['created_at' => now()]),
                    'updated_at' => now(),
                ],
            );
        }

        app(LookupCache::class)->forget(ChatProvider::class);

        $ensure = app(EnsureWhatsappConversation::class);

        WhatsappTarget::query()
            ->where(fn (EloquentBuilder $query) => $query->whereNotNull('last_message_at')->orWhereNotNull('pinned_at'))
            ->with('account')
            ->orderBy('id')
            ->chunkById(200, function (EloquentCollection $targets) use ($ensure): void {
                foreach ($targets as $target) {
                    $ensure($target);
                }
            });

        $tagIds = [];

        foreach (WhatsappTag::query()->orderBy('id')->cursor() as $tag) {
            $tagIds[$tag->id] = ChatTag::query()->firstOrCreate(
                ['user_id' => $tag->user_id, 'name' => $tag->name],
                ['color' => $tag->color],
            )->id;
        }

        if ($tagIds === []) {
            return;
        }

        $morph = (new WhatsappTarget)->getMorphClass();

        DB::table('whatsapp_tag_whatsapp_target')
            ->orderBy('whatsapp_tag_id')
            ->orderBy('whatsapp_target_id')
            ->chunk(500, function (Collection $rows) use ($tagIds, $morph): void {
                $entryIds = DB::table('chat_conversations')
                    ->where('conversable_type', $morph)
                    ->whereIn('conversable_id', $rows->pluck('whatsapp_target_id')->unique()->all())
                    ->pluck('id', 'conversable_id');

                $pivot = $rows
                    ->filter(fn (object $row): bool => isset($tagIds[$row->whatsapp_tag_id], $entryIds[$row->whatsapp_target_id]))
                    ->map(fn (object $row): array => [
                        'chat_tag_id' => $tagIds[$row->whatsapp_tag_id],
                        'chat_conversation_id' => $entryIds[$row->whatsapp_target_id],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                    ->values()
                    ->all();

                if ($pivot !== []) {
                    DB::table('chat_conversation_tag')->insertOrIgnore($pivot);
                }
            });
    }

    public function down(): void
    {
        DB::table('chat_conversations')->where('conversable_type', (new WhatsappTarget)->getMorphClass())->delete();
        DB::table('chat_providers')->where('slug', ChatProvider::WHATSAPP)->update(['is_active' => false, 'updated_at' => now()]);
    }
};
