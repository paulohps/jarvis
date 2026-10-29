<?php

use App\Models\Locale;
use App\Models\User;
use App\Services\Templates\TemplateContextBuilder;
use App\Services\Templates\TemplateRenderer;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateVariable;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('common variables are localized', function () {
    $instant = CarbonImmutable::parse('2026-10-15 12:00', 'UTC');
    $template = '{% current_month_name %} {% current_year %} · {% next_month_name %} · {% current_date %}';
    $renderer = new TemplateRenderer;

    $portuguese = User::factory()->create(['locale_id' => Locale::idFor(Locale::PT_BR), 'timezone' => 'UTC']);
    $english = User::factory()->create(['locale_id' => Locale::idFor(Locale::EN), 'timezone' => 'UTC']);

    expect(TemplateContextBuilder::forUser($portuguese)->common($instant)['current_month_name'])->toBe('outubro')
        ->and(TemplateContextBuilder::forUser($english)->common($instant)['current_month_name'])->toBe('October')
        ->and($renderer->renderText($template, TemplateContextBuilder::forUser($portuguese)->common($instant)))
        ->toBe('outubro 2026 · novembro · 15 de outubro de 2026')
        ->and($renderer->renderText($template, TemplateContextBuilder::forUser($english)->common($instant)))
        ->toBe('October 2026 · November · October 15, 2026');
});

test('common variables use the recipient timezone', function () {
    $instant = CarbonImmutable::parse('2026-11-01 01:00', 'UTC');

    $saoPaulo = TemplateContextBuilder::forUser(User::factory()->create([
        'locale_id' => Locale::idFor(Locale::PT_BR),
        'timezone' => 'America/Sao_Paulo',
    ]))->common($instant);

    $utc = (new TemplateContextBuilder(Locale::PT_BR, 'UTC'))->common($instant);

    expect($saoPaulo)->toBe([
        'current_month_name' => 'outubro',
        'current_year' => 2026,
        'current_date' => '31 de outubro de 2026',
        'next_month_name' => 'novembro',
    ])->and($utc['current_month_name'])->toBe('novembro');
});

test('common variables default to now', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-31 23:30', 'UTC'));

    expect((new TemplateContextBuilder(Locale::EN, 'Asia/Tokyo'))->common())
        ->toMatchArray(['current_month_name' => 'January', 'current_year' => 2027, 'next_month_name' => 'February']);
});

test('dates and amounts reach the renderer already formatted', function () {
    $schema = new class extends TemplateSchema
    {
        public function variables(): array
        {
            return [
                ...TemplateContextBuilder::commonVariables(),
                TemplateVariable::date('invoice.due_date', 'Due date', '2026-10-01'),
                TemplateVariable::dateTime('invoice.sent_at', 'Sent at', '2026-10-01 02:00'),
                TemplateVariable::money('invoice.amount', 'Amount', '1234567.5'),
                TemplateVariable::collection('items', 'Items', [
                    TemplateVariable::text('name', 'Name'),
                    TemplateVariable::money('price', 'Price'),
                ], [['name' => 'Hosting', 'price' => 2000]]),
            ];
        }
    };

    $sample = (new TemplateContextBuilder(Locale::PT_BR, 'America/Sao_Paulo'))
        ->sample($schema, CarbonImmutable::parse('2026-10-15 12:00', 'UTC'));

    expect($sample['invoice'])->toBe([
        'due_date' => '1 de outubro de 2026',
        'sent_at' => '30 de setembro de 2026 às 23:00',
        'amount' => '1,234,567.50',
    ])
        ->and($sample['items'])->toBe([['name' => 'Hosting', 'price' => '2,000.00']])
        ->and($sample['current_month_name'])->toBe('outubro');
});
