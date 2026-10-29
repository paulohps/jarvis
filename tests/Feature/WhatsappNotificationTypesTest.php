<?php

use App\Models\Locale;
use App\Models\Module;
use App\Models\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\Types\WhatsappAccountDisconnected;
use App\Services\Whatsapp\EvolutionApiClient;
use App\Support\Localization;
use Database\Seeders\LookupSeeder;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->artisan('notifications:provision')->assertSuccessful();

    FakeEvolutionApi::install();
});

test('disconnect notification goes only to the account owner', function () {
    // Provisioned: in the app, by e-mail and by push, never through WhatsApp.
    expect(app(NotificationRegistry::class)->classFor(NotificationType::WHATSAPP_ACCOUNT_DISCONNECTED))->toBe(WhatsappAccountDisconnected::class)
        ->and(WhatsappAccountDisconnected::module())->toBe(Module::WHATSAPP);

    $type = NotificationType::query()->where('slug', NotificationType::WHATSAPP_ACCOUNT_DISCONNECTED)->sole();

    expect($type->is_active)->toBeTrue()
        ->and($type->typeChannels()->with('channel')->get()->pluck('channel.slug')->sort()->values()->all())
        ->toBe([NotificationChannel::DATABASE, NotificationChannel::MAIL, NotificationChannel::PUSH]);

    foreach (WhatsappAccountDisconnected::channels() as $channel) {
        foreach (Localization::SUPPORTED_LOCALES as $locale) {
            $template = NotificationTemplate::query()
                ->whereRelation('typeChannel', 'notification_type_id', $type->id)
                ->whereRelation('typeChannel', 'notification_channel_id', NotificationChannel::idFor($channel))
                ->where('locale_id', Locale::idFor($locale))
                ->sole();

            expect($template->title)->toContain('{% account.nickname %}')
                ->and($template->body)->toContain('{% account.phone_number %}')
                ->and($template->actions)->toBe([['link_key' => 'reconnect', 'label' => $locale === Locale::PT_BR ? 'Reconectar' : 'Reconnect']]);
        }
    }

    // A real disconnection, through the webhook.
    config(['services.evolution.webhook_secret' => 'shared-secret']);

    $owner = User::factory()->withModules([Module::WHATSAPP])->create(['locale_id' => Locale::idFor(Locale::PT_BR)]);
    $member = User::factory()->withModules([Module::WHATSAPP])->create();
    $admin = User::factory()->superAdmin()->create();
    $account = WhatsappAccount::factory()->open()->for($owner)->create(['nickname' => 'Pessoal', 'phone_number' => '+5511999990000']);

    $this->postJson(route('webhooks.evolution'), [
        'event' => 'connection.update',
        'instance' => $account->instance_name,
        'data' => ['instance' => $account->instance_name, 'state' => 'close', 'statusReason' => 401],
    ], [EvolutionApiClient::WEBHOOK_SECRET_HEADER => 'shared-secret'])->assertOk();

    $data = $owner->notifications()->sole()->data;

    expect($data['key'])->toBe(NotificationType::WHATSAPP_ACCOUNT_DISCONNECTED)
        ->and($data['title'])->toBe('O WhatsApp Pessoal foi desconectado')
        ->and($data['body'])->toContain('+5511999990000 (Pessoal)')
        // The link leads to the reconnection screen.
        ->and($data['actions'])->toBe([['label' => 'Reconectar', 'url' => route('whatsapp.accounts.reconnect', $account)]])
        ->and($member->notifications()->count())->toBe(0)
        ->and($admin->notifications()->count())->toBe(0);

    $this->actingAs($owner)->get($data['actions'][0]['url'])->assertOk();

    // An owner who lost the module is not told either.
    $owner->memberships->each(fn ($membership) => $membership->modules()->detach());
    $owner->forgetMemberships();
    $owner->unsetRelation('modules');

    app(NotificationCenter::class)->dispatch(new WhatsappAccountDisconnected($account), $owner);

    expect($owner->notifications()->count())->toBe(1);
});
