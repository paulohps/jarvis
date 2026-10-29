<?php

use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Locale;
use App\Models\Module;
use App\Models\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\User;
use App\Services\Invoices\InvoiceNotifier;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\Types\InvoiceFailed;
use App\Services\Notifications\Types\InvoicePendingReview;
use App\Services\Notifications\Types\InvoiceSent;
use App\Support\Localization;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 11:05', 'UTC'));
});

test('invoice notification types are provisioned in both locales', function () {
    $this->artisan('notifications:provision')->assertSuccessful();

    $registered = app(NotificationRegistry::class)->map();

    foreach ([InvoiceSent::class, InvoiceFailed::class, InvoicePendingReview::class] as $class) {
        expect($registered)->toHaveKey($class::key())
            ->and($class::module())->toBe(Module::INVOICES)
            ->and($class::schema()->all())->toHaveKeys(['invoice.number', 'invoice.amount', 'invoice.currency', 'invoice.url', 'payer.name']);

        $type = NotificationType::query()->where('slug', $class::key())->sole();

        expect($type->is_active)->toBeTrue()
            ->and($type->typeChannels()->with('channel')->get()->pluck('channel.slug')->sort()->values()->all())
            ->toBe([NotificationChannel::DATABASE, NotificationChannel::MAIL, NotificationChannel::PUSH, NotificationChannel::WHATSAPP]);

        foreach ($class::channels() as $channel) {
            foreach (Localization::SUPPORTED_LOCALES as $locale) {
                $template = NotificationTemplate::query()
                    ->whereRelation('typeChannel', 'notification_type_id', $type->id)
                    ->whereRelation('typeChannel', 'notification_channel_id', NotificationChannel::idFor($channel))
                    ->where('locale_id', Locale::idFor($locale))
                    ->sole();

                expect($template->title)->toContain('{% invoice.number %}')
                    ->and($template->body)->toContain('{% payer.name %}');
            }
        }
    }

    expect(NotificationType::query()->where('slug', NotificationType::INVOICE_SENT)->sole()->typeChannels()->count())->toBe(4);
});

test('invoice notifications go only to users with the invoices module', function () {
    $this->artisan('notifications:provision')->assertSuccessful();

    $member = User::factory()->withModules([Module::INVOICES])->create(['name' => 'Ana', 'locale_id' => Locale::idFor(Locale::PT_BR)]);
    $admin = User::factory()->superAdmin()->create();
    $outsider = User::factory()->withModules([Module::SHOPPING])->create();
    $deactivated = User::factory()->withModules([Module::INVOICES])->deactivated()->create();

    $invoice = Invoice::factory()->oneOff()->pendingReview()->create([
        'amount' => '2000.00',
        'currency_id' => Currency::idFor(Currency::USD),
        'payer_name' => 'Initech Corp',
    ]);

    expect(InvoiceNotifier::audience($invoice)->pluck('id')->sort()->values()->all())->toBe(collect([$member->id, $admin->id])->sort()->values()->all());

    app(InvoiceNotifier::class)->pendingReview($invoice);

    $data = $member->notifications()->sole()->data;

    expect($data['key'])->toBe(NotificationType::INVOICE_PENDING_REVIEW)
        ->and($data['title'])->toBe("Invoice {$invoice->number} aguardando revisão")
        ->and($data['body'])->toContain('Initech Corp (2,000.00 Dólar americano)')
        // The link leads to the invoice.
        ->and($data['actions'])->toBe([['label' => 'Revisar a invoice', 'url' => route('invoices.history.show', $invoice)]])
        ->and($admin->notifications()->count())->toBe(1)
        ->and($outsider->notifications()->count())->toBe(0)
        ->and($deactivated->notifications()->count())->toBe(0);
});
