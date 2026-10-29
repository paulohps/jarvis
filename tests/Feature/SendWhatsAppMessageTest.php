<?php

use App\Actions\Whatsapp\SendWhatsAppMessage;
use App\Exceptions\EvolutionApiException;
use App\Jobs\DeliverWhatsappMessage;
use App\Models\DeliveryStatus;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use App\Services\Notifications\NotificationProvisioner;
use App\Services\Notifications\Types\WhatsappMessageFailed;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    app(NotificationProvisioner::class)->provisionAll();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    FakeEvolutionApi::install();

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create();
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create(['nickname' => 'Personal']);
    $this->target = WhatsappTarget::factory()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana', 'jid' => '5511911110000@s.whatsapp.net']);
});

test('deliveries are queued on the whatsapp queue with three attempts', function () {
    Queue::fake();

    $delivery = app(SendWhatsAppMessage::class)($this->account, $this->target, 'Hi Ana', WhatsappDeliverySource::MANUAL, triggeredBy: $this->owner);

    expect($delivery->status->slug)->toBe(DeliveryStatus::PENDING)
        ->and($delivery->source->slug)->toBe(WhatsappDeliverySource::MANUAL)
        ->and($delivery->triggered_by_id)->toBe($this->owner->id)
        ->and($delivery->body)->toBe('Hi Ana');

    Queue::assertPushedOn('whatsapp', DeliverWhatsappMessage::class, fn (DeliverWhatsappMessage $job): bool => $job->delivery->is($delivery));

    $job = new ReflectionClass(DeliverWhatsappMessage::class);

    expect($job->getAttributes(Tries::class)[0]->getArguments())->toBe([3])
        ->and($job->getAttributes(Backoff::class)[0]->getArguments())->toBe([30, 120]);
});

test('closed account fails fast without calling the api', function () {
    Notification::fake();
    $closed = WhatsappAccount::factory()->closed()->for($this->owner)->create(['nickname' => 'Work']);
    $target = WhatsappTarget::factory()->create(['whatsapp_account_id' => $closed->id]);

    $delivery = app(SendWhatsAppMessage::class)($closed, $target, 'Hello', WhatsappDeliverySource::MANUAL);

    expect($delivery->refresh()->status->slug)->toBe(DeliveryStatus::FAILED)
        ->and($delivery->error_message)->toContain('Work')->toContain('not connected')
        ->and($delivery->sent_at)->toBeNull()
        ->and($delivery->attempts)->toBe(0);

    Http::assertNothingSent();
    Notification::assertSentTo($this->owner, WhatsappMessageFailed::class, fn (WhatsappMessageFailed $notification): bool => $notification->delivery->is($delivery));
});

test('successful send stores external id', function () {
    Notification::fake();

    $delivery = app(SendWhatsAppMessage::class)($this->account, $this->target, "Hi Ana,\nsee you at 10.", WhatsappDeliverySource::MANUAL);

    $delivery->refresh();

    expect($delivery->status->slug)->toBe(DeliveryStatus::SENT)
        ->and($delivery->external_message_id)->toBe(FakeEvolutionApi::MESSAGE_ID)
        ->and($delivery->sent_at?->toIso8601String())->toBe('2026-10-05T12:00:00+00:00')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->error_message)->toBeNull();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/message/sendText/'.rawurlencode($this->account->instance_name))
        && $request['number'] === '5511911110000@s.whatsapp.net'
        && $request['text'] === "Hi Ana,\nsee you at 10.");
    Notification::assertNothingSent();
});

test('definitive failure notifies the owner', function () {
    Notification::fake();
    FakeEvolutionApi::install(['sendText' => Http::response(['response' => ['message' => 'Connection Closed']], 500)]);
    $member = User::factory()->withModules([Module::WHATSAPP])->create();

    // The sync queue runs a single attempt, then the job's failure handler.
    expect(fn () => app(SendWhatsAppMessage::class)($this->account, $this->target, 'Hi', WhatsappDeliverySource::MANUAL))
        ->toThrow(EvolutionApiException::class);

    $delivery = WhatsappDelivery::query()->sole();

    expect($delivery->status->slug)->toBe(DeliveryStatus::FAILED)
        ->and($delivery->error_message)->toContain('Connection Closed')
        ->and($delivery->external_message_id)->toBeNull();

    Notification::assertSentTo($this->owner, WhatsappMessageFailed::class, fn (WhatsappMessageFailed $notification): bool => $notification->delivery->is($delivery));
    Notification::assertNotSentTo($member, WhatsappMessageFailed::class);
});

test('duplicate scheduled slot is not sent twice', function () {
    $message = WhatsappMessage::factory()->recurring()->create(['user_id' => $this->owner->id, 'whatsapp_account_id' => $this->account->id]);
    $slot = CarbonImmutable::parse('2026-10-05 12:00', 'UTC');
    $send = app(SendWhatsAppMessage::class);

    $first = $send($this->account, $this->target, 'Hi', WhatsappDeliverySource::SCHEDULED, $message, $slot);
    $second = $send($this->account, $this->target, 'Hi', WhatsappDeliverySource::SCHEDULED, $message, $slot);

    expect($second->is($first))->toBeTrue()
        ->and(WhatsappDelivery::query()->count())->toBe(1);

    Http::assertSentCount(1);

    // The next slot is a delivery of its own.
    $send($this->account, $this->target, 'Hi', WhatsappDeliverySource::SCHEDULED, $message, $slot->addDay());

    expect(WhatsappDelivery::query()->count())->toBe(2);
    Http::assertSentCount(2);
});

test('a target of another account is refused', function () {
    $other = WhatsappTarget::factory()->create();

    expect(fn () => app(SendWhatsAppMessage::class)($this->account, $other, 'Hi', WhatsappDeliverySource::MANUAL))
        ->toThrow(InvalidArgumentException::class);

    expect(WhatsappDelivery::query()->count())->toBe(0);
});

test('only the delivery job calls the evolution api send endpoint', function () {
    $callers = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => str_contains((string) file_get_contents($file->getPathname()), '->sendText('))
        ->map(fn (SplFileInfo $file): string => str_replace(app_path().'/', '', $file->getPathname()))
        ->values()
        ->all();

    expect($callers)->toBe(['Jobs/DeliverWhatsappMessage.php']);
});
