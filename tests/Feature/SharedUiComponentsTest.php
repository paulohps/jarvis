<?php

use App\Models\Currency;
use App\Models\Locale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\App;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

test('localized date renders in the user timezone', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'America/Sao_Paulo']));

    $this->blade('<x-ui.localized-date :value="$value" format="time" />', [
        'value' => CarbonImmutable::parse('2026-10-01 11:00', 'UTC'),
    ])
        ->assertSee('08:00')
        ->assertSee('datetime="2026-10-01T08:00:00-03:00"', false);
});

test('localized date keeps the original instant in utc', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'America/Sao_Paulo']));
    $value = CarbonImmutable::parse('2026-10-01 02:00', 'UTC');

    $this->blade('<x-ui.localized-date :value="$value" />', ['value' => $value])
        ->assertSee('09/30/2026 23:00');

    expect($value->timezoneName)->toBe('UTC')->and($value->format('H:i'))->toBe('02:00');
});

test('localized date writes the date in the reader language', function () {
    App::setLocale(Locale::PT_BR);

    $this->blade('<x-ui.localized-date value="2026-10-01 11:00" format="date" timezone="America/Sao_Paulo" />')
        ->assertSee('01/10/2026');
});

test('calendar dates are never shifted', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'America/Sao_Paulo']));

    $this->blade('<x-ui.localized-date value="2026-10-01" format="calendar" />')
        ->assertSee('10/01/2026')
        ->assertSee('datetime="2026-10-01"', false);
});

test('an empty date shows a dash', function () {
    $this->blade('<x-ui.localized-date :value="null" />')->assertSee('—');
});

test('money uses two decimals and a thousands separator', function (string|int|float $amount, string $expected) {
    $this->blade('<x-ui.money :amount="$amount" currency="USD" />', ['amount' => $amount])
        ->assertSee($expected)
        ->assertSee('USD')
        ->assertSee('title="American dollar"', false);
})->with([
    ['2000', '2,000.00'],
    ['1234567.5', '1,234,567.50'],
    [0, '0.00'],
    [19.999, '20.00'],
]);

test('money accepts a currency model', function () {
    $this->blade('<x-ui.money amount="2000.00" :currency="$currency" />', ['currency' => Currency::bySlug(Currency::BRL)])
        ->assertSee('BRL')
        ->assertSee('2,000.00');
});

test('status badge colors the status by meaning', function (string $status, string $tone, string $label) {
    $this->blade('<x-ui.status-badge :status="$status" />', ['status' => $status])
        ->assertSee("text-{$tone}", false)
        ->assertSee($label);
})->with([
    ['sent', 'success', 'Sent'],
    ['pending_review', 'warning', 'Pending review'],
    ['failed', 'danger', 'Failed'],
    ['draft', 'secondary-label', 'Draft'],
]);

test('inset grouped list renders rows with header footer and links', function () {
    $this->blade(<<<'BLADE'
        <x-ui.inset-grouped-list header="Accounts" footer="Only yours are listed.">
            <x-ui.list-row title="Personal" subtitle="+55 67 99999-0000" icon="chat-bubble-left-right" href="/accounts/1">
                <x-slot:trailing><x-ui.status-badge status="open" /></x-slot:trailing>
            </x-ui.list-row>
            <x-ui.list-row title="Work" />
        </x-ui.inset-grouped-list>
        BLADE)
        ->assertSeeInOrder(['Accounts', 'Personal', '+55 67 99999-0000', 'Open', 'Work', 'Only yours are listed.'])
        ->assertSee('href="/accounts/1"', false)
        ->assertSee('divide-separator', false)
        ->assertSee('rounded-card', false);
});

test('segmented control is a radio group bound to wire model', function () {
    $this->blade('<x-ui.segmented-control name="filter" label="Filter" :options="[\'pending\' => \'Pending\', \'done\' => \'Done\']" value="done" wire:model.live="filter" />')
        ->assertSee('role="radiogroup"', false)
        ->assertSee('aria-label="Filter"', false)
        ->assertSee('wire:model.live="filter"', false)
        ->assertSeeInOrder(['value="pending"', 'value="done"', 'checked'], false);
});

test('empty state settings section page header and search field render their slots', function () {
    $this->blade(<<<'BLADE'
        <x-ui.page-header title="Lists" subtitle="Shared with the household">
            <x-slot:actions><button>New list</button></x-slot:actions>
        </x-ui.page-header>
        <x-ui.empty-state title="No lists yet" description="Create the first one.">
            <x-slot:actions><button>Create</button></x-slot:actions>
        </x-ui.empty-state>
        <x-ui.settings-section title="Language" description="The interface language.">
            <x-slot:status><span>Saved</span></x-slot:status>
            <p>Fields</p>
            <x-slot:actions><button>Save</button></x-slot:actions>
        </x-ui.settings-section>
        <x-ui.search-field wire:model.live="search" />
        BLADE)
        ->assertSeeInOrder(['<h1', 'Lists', 'Shared with the household', 'New list'], false)
        ->assertSeeInOrder(['No lists yet', 'Create the first one.', 'Create'])
        ->assertSeeInOrder(['id="settings-language"', 'Language', 'Saved', 'Fields', 'Save'], false)
        ->assertSee('type="search"', false)
        ->assertSee('placeholder="Search"', false)
        ->assertSee('wire:model.live="search"', false);
});

test('confirm dialog puts the action on the confirm button', function () {
    $this->blade(<<<'BLADE'
        <x-ui.confirm-dialog title="Delete list?" description="Its items are deleted too." confirm-label="Delete" danger wire:click="delete">
            <x-slot:trigger><button>Remove</button></x-slot:trigger>
        </x-ui.confirm-dialog>
        BLADE)
        ->assertSee('role="alertdialog"', false)
        ->assertSeeInOrder(['Remove', 'Delete list?', 'Its items are deleted too.', 'Cancel', 'wire:click="delete"', 'text-danger', 'Delete'], false);
});

test('every shared component documents its props and slots', function () {
    $components = ['PageHeader', 'InsetGroupedList', 'ListRow', 'SegmentedControl', 'EmptyState', 'SettingsSection', 'LocalizedDate', 'Money', 'ConfirmDialog', 'StatusBadge', 'SearchField'];

    foreach ($components as $component) {
        $doc = (new ReflectionClass("App\\View\\Components\\Ui\\{$component}"))->getDocComment();

        expect($doc)->toContain('Props')->toContain('Slots');
        expect(view()->exists('components.ui.'.Str::kebab($component)))->toBeTrue();
    }
});
