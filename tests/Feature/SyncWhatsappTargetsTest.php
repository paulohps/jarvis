<?php

use App\Events\WhatsappTargetsSynced;
use App\Exceptions\EvolutionApiException;
use App\Jobs\SyncWhatsappTargets;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use App\Models\WhatsappTargetType;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00', 'UTC'));
});

test('sync upserts contacts and groups without duplicates', function () {
    FakeEvolutionApi::install();
    Event::fake([WhatsappTargetsSynced::class]);

    $account = WhatsappAccount::factory()->open()->create(['last_synced_at' => null]);
    $otherAccount = WhatsappAccount::factory()->open()->create();

    // The same JID on another account is another target.
    WhatsappTarget::factory()->for($otherAccount, 'account')->create(['jid' => '5511911110000@s.whatsapp.net', 'name' => 'Ana (other)']);

    // A number typed by hand becomes a synced contact.
    $manual = WhatsappTarget::factory()->manual()->for($account, 'account')->create(['jid' => '5511922220000@s.whatsapp.net']);

    SyncWhatsappTargets::dispatchSync($account);

    // The owner's account list hears the sync is done.
    Event::assertDispatched(WhatsappTargetsSynced::class, fn (WhatsappTargetsSynced $event): bool => $event->userId === $account->user_id
        && $event->broadcastWith() === ['account_id' => $account->friendly_id]);

    $targets = fn () => $account->targets()->with('type')->orderBy('jid')->get()
        ->map(fn (WhatsappTarget $target): array => [$target->jid, $target->name, $target->type->slug, $target->is_synced])
        ->all();

    expect($targets())->toBe([
        ['120363000000000001@g.us', 'Family', WhatsappTargetType::GROUP, true],
        ['5511911110000@s.whatsapp.net', 'Ana', WhatsappTargetType::CONTACT, true],
        ['5511922220000@s.whatsapp.net', 'Bruno', WhatsappTargetType::CONTACT, true],
    ])
        ->and($manual->fresh()->friendly_id)->toBe($manual->friendly_id)
        ->and($account->targets()->pluck('friendly_id')->every(fn (string $id): bool => str_starts_with($id, 'wtgt_')))->toBeTrue()
        ->and($account->fresh()->last_synced_at->toIso8601String())->toBe('2026-10-03T12:00:00+00:00');

    $groupId = $account->targets()->where('jid', '120363000000000001@g.us')->value('id');

    // Syncing again updates the names, adds the new ones and duplicates nothing.
    $this->travel(1)->hour();

    FakeEvolutionApi::install([
        'fetchContacts' => [
            ['id' => 'c1', 'remoteJid' => '5511911110000@s.whatsapp.net', 'pushName' => 'Ana Paula'],
            ['id' => 'c2', 'remoteJid' => '5511922220000@s.whatsapp.net', 'pushName' => null],
            ['id' => 'c5', 'remoteJid' => '5511933330000@s.whatsapp.net', 'pushName' => 'Carla'],
        ],
        'fetchGroups' => [
            ['id' => '120363000000000001@g.us', 'subject' => 'Family 🏡'],
        ],
    ]);

    SyncWhatsappTargets::dispatchSync($account);

    expect($targets())->toBe([
        ['120363000000000001@g.us', 'Family 🏡', WhatsappTargetType::GROUP, true],
        ['5511911110000@s.whatsapp.net', 'Ana Paula', WhatsappTargetType::CONTACT, true],
        // A nameless entry keeps the name it had.
        ['5511922220000@s.whatsapp.net', 'Bruno', WhatsappTargetType::CONTACT, true],
        ['5511933330000@s.whatsapp.net', 'Carla', WhatsappTargetType::CONTACT, true],
    ])
        ->and($account->targets()->where('jid', '120363000000000001@g.us')->value('id'))->toBe($groupId)
        ->and($account->fresh()->last_synced_at->toIso8601String())->toBe('2026-10-03T13:00:00+00:00')
        ->and($otherAccount->targets()->sole()->name)->toBe('Ana (other)');

    // The webhook is pointed at the current events first.
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/webhook/set/'.$account->instance_name)
        && in_array('MESSAGES_UPSERT', $request['webhook']['events'], true));
});

test('contacts are kept when the groups listing times out', function () {
    FakeEvolutionApi::install(['fetchGroups' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

    $account = WhatsappAccount::factory()->open()->create(['last_synced_at' => null]);

    expect(fn () => SyncWhatsappTargets::dispatchSync($account))->toThrow(EvolutionApiException::class);

    expect($account->targets()->count())->toBe(2)
        ->and($account->fresh()->last_synced_at)->toBeNull();
});

test('the sync job outlasts both listings but not the queue retry', function () {
    expect((new ReflectionClass(SyncWhatsappTargets::class))->getAttributes(Timeout::class)[0]->newInstance()->timeout)
        ->toBeLessThan((int) config('queue.connections.redis.retry_after'))
        ->toBeGreaterThan(2 * (int) config('services.evolution.sync_timeout'));
});

test('sync keeps the profile pictures, and a contact without one keeps the picture it had', function () {
    FakeEvolutionApi::install();
    $account = WhatsappAccount::factory()->open()->create();
    $bruno = WhatsappTarget::factory()->for($account, 'account')->create(['jid' => '5511922220000@s.whatsapp.net', 'picture_url' => 'https://pps.whatsapp.net/bruno.jpg']);

    SyncWhatsappTargets::dispatchSync($account);

    expect($account->targets()->where('jid', '5511911110000@s.whatsapp.net')->value('picture_url'))->toBe(FakeEvolutionApi::PICTURE_URL)
        ->and($bruno->fresh()->picture_url)->toBe('https://pps.whatsapp.net/bruno.jpg');
});
