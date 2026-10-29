<?php

use App\Models\DeliveryStatus;
use App\Models\Module;
use App\Models\Recurrence;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 11:59', 'UTC'));

    FakeEvolutionApi::install();

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create(['name' => 'Paulo', 'timezone' => 'America/Sao_Paulo']);
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create();
    $this->ana = WhatsappTarget::factory()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana', 'jid' => '5511911110000@s.whatsapp.net']);
    $this->family = WhatsappTarget::factory()->group()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Family']);

    // Every day at 09:00 in São Paulo (12:00 UTC).
    $this->message = WhatsappMessage::factory()
        ->has(Recurrence::factory()->daily()->state(['time_of_day' => '09:00', 'timezone' => 'America/Sao_Paulo', 'next_run_at' => '2026-10-05 12:00:00']), 'recurrence')
        ->create([
            'user_id' => $this->owner->id,
            'whatsapp_account_id' => $this->account->id,
            'body' => 'Bom dia {% recipient.name %}! — {% sender.name %}',
        ]);
    $this->message->targets()->attach([$this->ana->id, $this->family->id]);
});

test('scheduled slot sends one delivery per target', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    $deliveries = WhatsappDelivery::query()->with(['status', 'source'])->orderBy('whatsapp_target_id')->get();

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->pluck('whatsapp_target_id')->all())->toBe([$this->ana->id, $this->family->id])
        ->and($deliveries->pluck('body')->all())->toBe(['Bom dia Ana! — Paulo', 'Bom dia Family! — Paulo'])
        ->and($deliveries->every(fn (WhatsappDelivery $delivery): bool => $delivery->source->slug === WhatsappDeliverySource::SCHEDULED
            && $delivery->status->slug === DeliveryStatus::SENT
            && $delivery->whatsapp_message_id === $this->message->id
            && $delivery->scheduled_for?->toIso8601String() === '2026-10-05T12:00:00+00:00'))->toBeTrue();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request['number'] === '5511911110000@s.whatsapp.net' && $request['text'] === 'Bom dia Ana! — Paulo');

    // Running the same slot again (a retried job) sends nothing twice.
    $this->message->runScheduledSlot(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    expect(WhatsappDelivery::query()->count())->toBe(2);
    Http::assertSentCount(2);
});

test('recurring message sends again on the next slot', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    expect($this->message->recurrence->refresh()->next_run_at->toIso8601String())->toBe('2026-10-06T12:00:00+00:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    expect(WhatsappDelivery::query()->count())->toBe(4)
        ->and(WhatsappDelivery::query()->distinct()->pluck('scheduled_for')->map->toIso8601String()->sort()->values()->all())
        ->toBe(['2026-10-05T12:00:00+00:00', '2026-10-06T12:00:00+00:00']);

    Http::assertSentCount(4);
});

test('cancelled or deleted messages send nothing', function () {
    $slot = CarbonImmutable::parse('2026-10-05 12:00', 'UTC');

    $this->message->update(['cancelled_at' => now()]);
    $this->message->runScheduledSlot($slot);

    $deleted = WhatsappMessage::factory()->recurring()->withTargets()->create(['user_id' => $this->owner->id, 'whatsapp_account_id' => $this->account->id]);
    $deleted->delete();
    $deleted->runScheduledSlot($slot);

    expect(WhatsappDelivery::query()->count())->toBe(0);
    Http::assertNothingSent();
});
