<?php

use App\Actions\Notifications\SaveNotificationTemplates;
use App\Actions\Notifications\UpdateNotificationType;
use App\Actions\Notifications\UpdateNotificationTypeChannel;
use App\Livewire\Admin\Notifications\NotificationTemplateEditor;
use App\Livewire\Admin\Notifications\NotificationTypeList;
use App\Models\Locale;
use App\Models\Module;
use App\Models\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\NotificationProvisioner;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\Types\ModuleEnabled;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    app(NotificationProvisioner::class)->provisionAll();

    $this->admin = User::factory()->superAdmin()->create(['name' => 'Paulo']);
    $this->moduleEnabled = NotificationType::query()->where('slug', NotificationType::MODULE_ENABLED)->sole();
});

function moduleEnabledTemplate(string $channel, string $locale): NotificationTemplate
{
    return NotificationTemplate::query()
        ->whereRelation('typeChannel.type', 'slug', NotificationType::MODULE_ENABLED)
        ->whereRelation('typeChannel', 'notification_channel_id', NotificationChannel::idFor($channel))
        ->where('locale_id', Locale::idFor($locale))
        ->sole();
}

test('non super admin cannot manage notification templates', function () {
    $member = User::factory()->create();

    $this->actingAs($member)->get(route('admin.notifications.index'))->assertForbidden();
    $this->actingAs($member)->get(route('admin.notifications.edit', $this->moduleEnabled->slug))->assertForbidden();
    $this->actingAs($member)->get(route('admin.index'))->assertForbidden();

    expect(fn () => app(SaveNotificationTemplates::class)($member, $this->moduleEnabled, [
        'mail' => ['en' => ['title' => 'Hacked', 'body' => '', 'actions' => []]],
    ]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateNotificationType::class)($member, $this->moduleEnabled, ['is_active' => false]))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateNotificationTypeChannel::class)($member, NotificationChannel::bySlug('mail'), ['is_active' => false]))
        ->toThrow(AuthorizationException::class)
        ->and(moduleEnabledTemplate('mail', 'en')->title)->toBe('{% module.name %} is now available')
        ->and($this->moduleEnabled->fresh()->is_active)->toBeTrue();
});

test('super admin opens the notification admin', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.notifications.index'))
        ->assertOk()
        ->assertSee('Module enabled')
        ->assertSee('Invitation')
        ->assertSee('data-type-channel="module_enabled.database"', false)
        ->assertSee('data-type-channel="invitation.mail"', false)
        ->assertDontSee('data-type-channel="invitation.database"', false);

    $this->actingAs($this->admin)
        ->get(route('admin.notifications.edit', ['notificationType' => $this->moduleEnabled->slug, 'channel' => 'mail']))
        ->assertOk()
        ->assertSee('module.name')
        ->assertSee('{% module.name %} is now available');
});

test('saving template records last editor', function () {
    Livewire::actingAs($this->admin)
        ->test(NotificationTemplateEditor::class, ['notificationType' => $this->moduleEnabled])
        ->call('selectTab', 'mail', 'pt_BR')
        ->set('templates.mail.pt_BR.title', 'Novo módulo: {% module.name %}')
        ->set('templates.mail.pt_BR.body', '<p onclick="x()">Olá, {% user.name %}</p><script>alert(1)</script>')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('statusMessage', '1 text saved; the next notifications already use it.');

    $template = moduleEnabledTemplate('mail', 'pt_BR');

    expect($template->title)->toBe('Novo módulo: {% module.name %}')
        ->and($template->body)->toBe('<p>Olá, {% user.name %}</p>')
        ->and($template->updated_by_id)->toBe($this->admin->id)
        ->and(moduleEnabledTemplate('mail', 'en')->updated_by_id)->toBeNull();

    // The next notification already uses it.
    $reader = User::factory()->create(['locale_id' => Locale::idFor(Locale::PT_BR)]);
    app(NotificationCenter::class)->dispatch(new ModuleEnabled(Module::bySlug(Module::REMINDERS)), $reader);

    expect(app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage()->getSubject())
        ->toBe('Novo módulo: Agenda');
});

test('invalid template is rejected', function () {
    Livewire::actingAs($this->admin)
        ->test(NotificationTemplateEditor::class, ['notificationType' => $this->moduleEnabled])
        ->set('templates.mail.pt_BR.title', 'Olá {% invoice.number %}')
        ->set('templates.database.en.body', '{% if module.name %}unclosed')
        ->set('templates.database.en.actions', [['link_key' => 'elsewhere', 'label' => 'Go']])
        ->call('save')
        ->assertHasErrors([
            'templates.mail.pt_BR.title',
            'templates.database.en.body',
            'templates.database.en.actions.0.link_key',
        ])
        // The first tab with a problem opens.
        ->assertSet('channel', 'database')
        ->assertSet('locale', 'en');

    expect(moduleEnabledTemplate('mail', 'pt_BR')->title)->toBe('O módulo {% module.name %} foi liberado')
        ->and(moduleEnabledTemplate('mail', 'pt_BR')->updated_by_id)->toBeNull()
        ->and(moduleEnabledTemplate('database', 'en')->body)->toBe('<p>Hi, {% user.name %}. You can now use {% module.name %} in Jarvis.</p>');
});

test('the editor previews the open tab with sample data', function () {
    Livewire::actingAs($this->admin)
        ->test(NotificationTemplateEditor::class, ['notificationType' => $this->moduleEnabled])
        ->assertSee('Reminders is now available')
        ->set('templates.database.en.title', 'Ready: {% module.name %}')
        ->assertSee('Ready: Reminders')
        ->set('templates.database.en.title', 'Broken {% unknown %}')
        ->assertSee('Unknown variable &quot;unknown&quot;.', false);
});

test('a language without a text can be written from the defaults', function () {
    moduleEnabledTemplate('database', 'pt_BR')->delete();

    Livewire::actingAs($this->admin)
        ->test(NotificationTemplateEditor::class, ['notificationType' => $this->moduleEnabled])
        ->call('selectTab', 'database', 'pt_BR')
        ->assertSee(__('There is no text in this language: its readers get the English text.'))
        ->call('writeTranslation')
        ->assertSet('templates.database.pt_BR.title', 'O módulo {% module.name %} foi liberado')
        ->call('save')
        ->assertHasNoErrors();

    expect(moduleEnabledTemplate('database', 'pt_BR')->updated_by_id)->toBe($this->admin->id);
});

test('disabled type stops dispatching', function () {
    Queue::fake();

    $user = User::factory()->create();

    Livewire::actingAs($this->admin)
        ->test(NotificationTypeList::class)
        ->assertSet('active.module_enabled', true)
        ->set('active.module_enabled', false)
        ->assertSet('active.module_enabled', false);

    expect($this->moduleEnabled->fresh()->is_active)->toBeFalse();

    app(NotificationCenter::class)->dispatch(new ModuleEnabled(Module::bySlug(Module::REMINDERS)), $user);

    Queue::assertNotPushed(SendQueuedNotifications::class);
});

test('switching a channel off on a type stops it on that channel only', function () {
    Queue::fake();

    $user = User::factory()->create();

    Livewire::actingAs($this->admin)
        ->test(NotificationTypeList::class)
        ->set('channelActive.module_enabled.mail', false)
        ->set('enabledByDefault.module_enabled.database', true);

    app(NotificationCenter::class)->dispatch(new ModuleEnabled(Module::bySlug(Module::REMINDERS)), $user);

    Queue::assertPushed(SendQueuedNotifications::class, 1);
    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->channels !== ['mail']);
});

test('disabling a platform channel hides it from every users preferences', function () {
    $registry = app(NotificationRegistry::class);

    expect($registry->preferenceMatrix())->toBe([
        NotificationType::INVITATION => ['mail'],
        NotificationType::MODULE_ENABLED => ['database', 'mail', 'whatsapp'],
        NotificationType::INVOICE_SENT => ['database', 'mail', 'push', 'whatsapp'],
        NotificationType::INVOICE_FAILED => ['database', 'mail', 'push', 'whatsapp'],
        NotificationType::INVOICE_PENDING_REVIEW => ['database', 'mail', 'push', 'whatsapp'],
        NotificationType::WHATSAPP_ACCOUNT_DISCONNECTED => ['database', 'mail', 'push'],
        NotificationType::WHATSAPP_MESSAGE_FAILED => ['database', 'mail', 'push'],
        NotificationType::WHATSAPP_MESSAGE_RECEIVED => ['push'],
        NotificationType::REMINDER_DUE => ['database', 'mail', 'push', 'whatsapp'],
        NotificationType::CHAT_MESSAGE_RECEIVED => ['push'],
    ]);

    Livewire::actingAs($this->admin)
        ->test(NotificationTypeList::class)
        ->set('platformActive.mail', false)
        ->assertSee(__('Switched off on the platform'));

    expect(NotificationChannel::bySlug('mail')->is_active)->toBeFalse()
        ->and($registry->preferenceMatrix())->toBe([
            NotificationType::MODULE_ENABLED => ['database', 'whatsapp'],
            NotificationType::INVOICE_SENT => ['database', 'push', 'whatsapp'],
            NotificationType::INVOICE_FAILED => ['database', 'push', 'whatsapp'],
            NotificationType::INVOICE_PENDING_REVIEW => ['database', 'push', 'whatsapp'],
            NotificationType::WHATSAPP_ACCOUNT_DISCONNECTED => ['database', 'push'],
            NotificationType::WHATSAPP_MESSAGE_FAILED => ['database', 'push'],
            NotificationType::WHATSAPP_MESSAGE_RECEIVED => ['push'],
            NotificationType::REMINDER_DUE => ['database', 'push', 'whatsapp'],
            NotificationType::CHAT_MESSAGE_RECEIVED => ['push'],
        ]);
});
