<?php

use App\Exceptions\UnknownLookupSlug;
use App\Models\AiProvider;
use App\Models\Currency;
use App\Models\DeliveryStatus;
use App\Models\DevicePlatform;
use App\Models\InvoiceDateRule;
use App\Models\InvoiceDeliveryMode;
use App\Models\InvoiceDueDateRule;
use App\Models\InvoiceStatus;
use App\Models\Locale;
use App\Models\Lookup;
use App\Models\Module;
use App\Models\NotificationChannel;
use App\Models\RecurrenceFrequency;
use App\Models\SocialProvider;
use App\Models\Weekday;
use App\Models\WhatsappConnectionStatus;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappTargetType;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('lookup id for resolves seeded slugs and caches them', function () {
    $sentId = DB::table('invoice_statuses')->where('slug', 'sent')->value('id');

    DB::enableQueryLog();

    expect(InvoiceStatus::idFor(InvoiceStatus::SENT))->toBe($sentId);

    $queriesAfterFirstCall = count(DB::getQueryLog());

    expect(InvoiceStatus::idFor(InvoiceStatus::SENT))->toBe($sentId)
        ->and(InvoiceStatus::idFor(InvoiceStatus::DRAFT))->toBeInt()
        ->and(count(DB::getQueryLog()))->toBe($queriesAfterFirstCall)
        ->and($queriesAfterFirstCall)->toBe(1);
});

test('lookup id for rejects an unknown slug', function () {
    InvoiceStatus::idFor('archived');
})->throws(UnknownLookupSlug::class);

test('saving a lookup row forgets the cached table', function () {
    expect(fn () => Currency::idFor('GBP'))->toThrow(UnknownLookupSlug::class);

    $pound = Currency::query()->create(['slug' => 'GBP', 'name' => 'Pound sterling', 'symbol' => '£']);

    expect(Currency::idFor('GBP'))->toBe($pound->id);

    $pound->update(['slug' => 'GBX']);

    expect(Currency::idFor('GBX'))->toBe($pound->id)
        ->and(fn () => Currency::idFor('GBP'))->toThrow(UnknownLookupSlug::class);
});

test('active scope keeps only active rows', function () {
    Locale::query()->where('slug', Locale::PT_BR)->update(['is_active' => false]);

    expect(Locale::query()->active()->pluck('slug')->all())->toBe([Locale::EN]);
});

test('every seeded slug has a constant', function (string $lookupClass) {
    $constants = collect((new ReflectionClass($lookupClass))->getReflectionConstants())
        ->filter(fn (ReflectionClassConstant $constant) => $constant->getDeclaringClass()->getName() === $lookupClass)
        ->map(fn (ReflectionClassConstant $constant) => $constant->getValue());

    /** @var class-string<Lookup> $lookupClass */
    $seededSlugs = $lookupClass::query()->pluck('slug');

    expect($seededSlugs)->not->toBeEmpty()
        ->and($seededSlugs->diff($constants)->all())->toBe([]);
})->with([
    Locale::class, Module::class, SocialProvider::class, DevicePlatform::class, AiProvider::class,
    NotificationChannel::class, RecurrenceFrequency::class, Weekday::class, DeliveryStatus::class,
    Currency::class, InvoiceStatus::class, InvoiceDeliveryMode::class, InvoiceDateRule::class,
    InvoiceDueDateRule::class, WhatsappConnectionStatus::class, WhatsappTargetType::class,
    WhatsappDeliverySource::class,
]);

test('currency label is translated', function () {
    expect(Currency::labelFor(Currency::USD))->toBe('American dollar');

    App::setLocale('pt_BR');

    expect(Currency::labelFor('USD'))->toBe('Dólar americano')
        ->and(Currency::bySlug(Currency::BRL)->label())->toBe('Real brasileiro');
});
