<?php

use App\Models\Invitation;
use App\Models\Locale;
use App\Models\Module;
use App\Models\Notification as StoredNotification;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\NotificationProvisioner;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\Types\InvitationReceived;
use App\Services\Notifications\Types\ModuleEnabled;
use App\Services\Templates\TemplateValidator;
use App\Support\Localization;
use Database\Seeders\LookupSeeder;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Collection;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    app(NotificationProvisioner::class)->provisionAll();
});

/**
 * @return Collection<int, SentMessage>
 */
function sentMails(): Collection
{
    return app('mailer')->getSymfonyTransport()->messages();
}

test('invitation notification is mailed to a non user address', function () {
    $inviter = User::factory()->superAdmin()->create(['name' => 'Paulo']);
    $workspace = Workspace::factory()->create(['name' => 'Costa family']);
    $invitation = Invitation::factory()->for($inviter, 'invitedBy')->for($workspace)->create(['email' => 'guest@example.com']);
    $invitation->modules()->attach([Module::idFor(Module::INVOICES), Module::idFor(Module::REMINDERS)]);
    $acceptUrl = url('/invitations/plain-token');

    app(NotificationCenter::class)->dispatch(
        new InvitationReceived($invitation, $acceptUrl),
        InvitationReceived::recipientFor($invitation),
    );

    $mails = sentMails();

    expect($mails)->toHaveCount(1);

    $email = $mails->first()->getOriginalMessage();
    $html = $email->getHtmlBody();

    expect($email->getTo()[0]->getAddress())->toBe('guest@example.com')
        ->and($email->getSubject())->toBe('Paulo invited you to Jarvis')
        ->and($html)->toContain('Paulo invited you to join Costa family on Jarvis.')
        ->and($html)->toContain('Invoices')
        ->and($html)->toContain('Agenda')
        ->and($html)->toContain('Accept the invitation')
        ->and($html)->toContain($acceptUrl)
        ->and($html)->not->toContain('Manage notifications')
        ->and(StoredNotification::query()->count())->toBe(0)
        ->and(User::query()->where('email', 'guest@example.com')->exists())->toBeFalse();
});

test('invitation is written in the language chosen in the invitation', function () {
    $inviter = User::factory()->superAdmin()->create(['name' => 'Paulo']);
    $invitation = Invitation::factory()->for($inviter, 'invitedBy')->create([
        'email' => 'convidada@example.com',
        'locale_id' => Locale::idFor(Locale::PT_BR),
    ]);

    app(NotificationCenter::class)->dispatch(
        new InvitationReceived($invitation, url('/invitations/plain-token')),
        InvitationReceived::recipientFor($invitation),
    );

    $email = sentMails()->sole()->getOriginalMessage();

    expect($email->getSubject())->toBe('Paulo convidou você para o Jarvis')
        ->and($email->getHtmlBody())->toContain('no espaço Household')
        ->and($email->getHtmlBody())->toContain('Aceitar o convite')
        ->and($email->getHtmlBody())->toContain('O convite vale até');
});

test('module enabled notification renders module name in recipient locale', function () {
    $user = User::factory()->create(['name' => 'Bia', 'locale_id' => Locale::idFor(Locale::PT_BR)]);

    app(NotificationCenter::class)->dispatch(new ModuleEnabled(Module::bySlug(Module::SHOPPING)), $user);

    $data = $user->notifications()->sole()->data;

    expect($data['title'])->toBe('O módulo Lista de compras foi liberado')
        ->and($data['body'])->toBe('<p>Olá, Bia. Agora você pode usar o módulo Lista de compras no Jarvis.</p>')
        ->and($data['actions'])->toBe([['label' => 'Abrir Lista de compras', 'url' => route('shopping.index')]])
        ->and(sentMails()->sole()->getOriginalMessage()->getSubject())->toBe('O módulo Lista de compras foi liberado');
});

test('module enabled notification is written in english for an english reader', function () {
    $user = User::factory()->create(['name' => 'Ana']);

    app(NotificationCenter::class)->dispatch(new ModuleEnabled(Module::bySlug(Module::SHOPPING)), $user);

    expect($user->notifications()->sole()->data['title'])->toBe('Shopping list is now available');
});

test('every registered type declares its schema and valid defaults in both locales', function () {
    $validator = new TemplateValidator;

    foreach (app(NotificationRegistry::class)->classes() as $class) {
        $schema = $class::schema();

        expect($schema->all())->not->toBeEmpty();

        foreach ($class::channels() as $channel) {
            foreach (Localization::SUPPORTED_LOCALES as $locale) {
                $defaults = $class::defaults($channel, $locale);

                expect($validator->validate($defaults->title, $schema))->toBe([], "{$class} {$channel} {$locale} title")
                    ->and($validator->validate($defaults->body, $schema))->toBe([], "{$class} {$channel} {$locale} body");

                foreach ($defaults->actions as $action) {
                    expect($class::links())->toHaveKey($action['link_key'])
                        ->and($validator->validate($action['label'], $schema))->toBe([]);
                }
            }
        }
    }
});
