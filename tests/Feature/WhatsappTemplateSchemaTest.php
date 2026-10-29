<?php

use App\Actions\Templates\PreviewTemplate;
use App\Models\Locale;
use App\Models\Module;
use App\Models\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappTarget;
use App\Rules\ValidTemplate;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\Types\WhatsappMessageFailed;
use App\Services\Whatsapp\WhatsappTemplateSchema;
use App\Support\Localization;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));
});

test('whatsapp body renders as plain text', function () {
    $sender = User::factory()->create(['name' => 'Paulo', 'locale_id' => Locale::idFor(Locale::PT_BR), 'timezone' => 'America/Sao_Paulo']);
    $target = WhatsappTarget::factory()->create(['name' => 'Ana & <Bia>']);
    $schema = new WhatsappTemplateSchema;

    $body = "<b>Oi {% recipient.name %}</b>,\n<script>alert(1)</script>lembrete de {% current_month_name %} {% current_year %}.\n— {% sender.name %}";

    expect($schema->render($body, $target, $sender))
        // The writer's tags are dropped; a value is printed as it is.
        ->toBe("Oi Ana & <Bia>,\nalert(1)lembrete de outubro 2026.\n— Paulo")
        ->not->toContain('<b>')
        ->not->toContain('<script>')
        ->not->toContain('&amp;');

    // A typed number has no name: conditions can leave it out.
    $typed = WhatsappTarget::factory()->manual()->create();

    expect($schema->render('Hi{% if recipient.name %} {% recipient.name %}{% endif %}!', $typed, $sender))->toBe('Hi!');

    // Only the declared variables are accepted when saving.
    expect(array_keys($schema->all()))->toContain('recipient.name', 'sender.name', 'current_month_name', 'current_year')
        ->and(Validator::make(['body' => 'Hi {% recipient.name %}'], ['body' => [new ValidTemplate($schema)]])->passes())->toBeTrue()
        ->and(Validator::make(['body' => 'Hi {% invoice.number %}'], ['body' => [new ValidTemplate($schema)]])->passes())->toBeFalse();

    // The preview is plain text too.
    $preview = app(PreviewTemplate::class)($schema, '<i>Hi</i> {% recipient.name %}', $sender);

    expect($preview->isHtml())->toBeFalse()
        ->and($preview->output)->toBe('Hi Ana');
});

test('whatsapp message failed type is provisioned', function () {
    $this->artisan('notifications:provision')->assertSuccessful();

    expect(app(NotificationRegistry::class)->classFor(NotificationType::WHATSAPP_MESSAGE_FAILED))->toBe(WhatsappMessageFailed::class);

    $type = NotificationType::query()->where('slug', NotificationType::WHATSAPP_MESSAGE_FAILED)->sole();

    expect($type->is_active)->toBeTrue()
        ->and($type->typeChannels()->with('channel')->get()->pluck('channel.slug')->sort()->values()->all())
        ->toBe([NotificationChannel::DATABASE, NotificationChannel::MAIL, NotificationChannel::PUSH]);

    foreach (WhatsappMessageFailed::channels() as $channel) {
        foreach (Localization::SUPPORTED_LOCALES as $locale) {
            $template = NotificationTemplate::query()
                ->whereRelation('typeChannel', 'notification_type_id', $type->id)
                ->whereRelation('typeChannel', 'notification_channel_id', NotificationChannel::idFor($channel))
                ->where('locale_id', Locale::idFor($locale))
                ->sole();

            expect($template->title)->toContain('{% target.name %}')
                ->and($template->body)->toContain('{% error %}');
        }
    }

    // Written for the owner in their language.
    $owner = User::factory()->withModules([Module::WHATSAPP])->create(['locale_id' => Locale::idFor(Locale::PT_BR)]);
    $account = WhatsappAccount::factory()->open()->for($owner)->create(['nickname' => 'Pessoal']);
    $delivery = WhatsappDelivery::factory()->failed()->create([
        'whatsapp_account_id' => $account->id,
        'whatsapp_target_id' => WhatsappTarget::factory()->manual()->create(['whatsapp_account_id' => $account->id, 'jid' => '5511933330000@s.whatsapp.net'])->id,
        'body' => 'Bom dia!',
        'error_message' => 'Sem conexão',
    ]);

    $rendered = (new WhatsappMessageFailed($delivery))->renderFor($owner, NotificationChannel::DATABASE);

    expect($rendered->title)->toBe('Falha ao enviar WhatsApp para +5511933330000')
        ->and($rendered->body)->toContain('“Bom dia!”')->toContain('Pessoal')->toContain('Sem conexão');
});
