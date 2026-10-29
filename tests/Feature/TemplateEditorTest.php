<?php

use App\Livewire\Admin\Notifications\NotificationTemplateEditor;
use App\Livewire\Invoices\InvoiceRecurrenceForm;
use App\Livewire\Whatsapp\MessageForm;
use App\Models\Module;
use App\Models\NotificationType;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Rules\ValidTemplate;
use App\Services\Notifications\NotificationProvisioner;
use App\Services\Templates\TemplateContextBuilder;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateVariable;
use App\View\Components\Ui\TemplateEditor;
use Database\Seeders\LookupSeeder;
use Livewire\Component;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

function editorSchema(): TemplateSchema
{
    return new class extends TemplateSchema
    {
        public function variables(): array
        {
            return [
                ...TemplateContextBuilder::commonVariables(),
                TemplateVariable::text('invoice.number', 'Invoice number', '2026-0042'),
                TemplateVariable::text('invoice.currency', 'Currency', 'BRL', ['BRL', 'USD']),
                TemplateVariable::boolean('invoice.is_overdue', 'Whether the invoice is overdue', false),
                TemplateVariable::collection('invoice.items', 'Items', [
                    TemplateVariable::text('name', 'Name'),
                    TemplateVariable::text('status', 'Status', values: ['paid', 'open']),
                ]),
            ];
        }
    };
}

test('the editor hands the schema variables to the script', function () {
    $config = (new TemplateEditor(editorSchema(), name: 'body', value: 'Hi {% invoice.number %}'))->config('body');

    expect($config['name'])->toBe('body')
        ->and($config['mode'])->toBe(TemplateEditor::MODE_PLAIN)
        ->and($config['value'])->toBe('Hi {% invoice.number %}')
        ->and(array_column($config['variables'], 'path'))->toBe([
            'current_month_name', 'current_year', 'current_date', 'next_month_name',
            'invoice.number', 'invoice.currency', 'invoice.is_overdue',
        ])
        ->and($config['variables'][4])->toBe(['path' => 'invoice.number', 'description' => 'Invoice number', 'isFlag' => false])
        ->and($config['variables'][6]['isFlag'])->toBeTrue()
        ->and($config['collections'])->toBe([[
            'path' => 'invoice.items',
            'description' => 'Items',
            'fields' => [
                ['name' => 'name', 'description' => 'Name', 'isFlag' => false],
                ['name' => 'status', 'description' => 'Status', 'isFlag' => false],
            ],
        ]])
        ->and((array) $config['values'])->toBe([
            'invoice.currency' => ['BRL', 'USD'],
            'invoice.items.status' => ['paid', 'open'],
        ])
        ->and($config['labels']['repeatFor'])->toBe('Repeat for each item in :list')
        ->and($config['labels']['unsupported'])->toHaveKeys(['unfinished_tag', 'unknown_tag', 'else_branch', 'block_in_list']);
});

test('the editor descriptions follow the reader language', function () {
    app()->setLocale('pt_BR');

    $config = (new TemplateEditor(editorSchema()))->config(null);

    expect($config['variables'][0]['description'])->toBe(__('The name of the current month'))
        ->and($config['labels']['edit'])->toBe('Editar');
});

test('the editor refuses an unknown mode', function () {
    new TemplateEditor(editorSchema(), mode: 'markdown');
})->throws(InvalidArgumentException::class);

test('a rich editor offers formatting and a plain one does not', function () {
    $this->blade('<x-ui.template-editor :schema="$schema" name="body" label="Body" mode="rich" />', ['schema' => editorSchema()])
        ->assertSee('data-template-editor="body"', false)
        ->assertSee('data-template-editor-mode="rich"', false)
        ->assertSee('aria-label="Bold"', false)
        ->assertSee('aria-label="Bulleted list"', false)
        ->assertSee('data-template-editor-menu="variable"', false)
        ->assertSee('data-template-editor-menu="if"', false)
        ->assertSee('data-template-editor-menu="for"', false)
        ->assertSee('Edit as code')
        ->assertSee('aria-multiline="true"', false)
        ->assertSee('id="template-body-label"', false)
        ->assertSee('name="body"', false);

    $this->blade('<x-ui.template-editor :schema="$schema" name="title" mode="inline" placeholder="Write the title" />', ['schema' => editorSchema()])
        ->assertSee('data-template-editor-mode="inline"', false)
        ->assertDontSee('aria-label="Bold"', false)
        ->assertSee('aria-multiline="false"', false)
        ->assertSee('data-template-editor-menu="variable"', false)
        ->assertSee('Write the title')
        ->assertDontSee('placeholder="Write the title"', false);

    $this->blade('<x-ui.template-editor :schema="$schema" name="message" />', ['schema' => editorSchema()])
        ->assertSee('data-template-editor-mode="plain"', false)
        ->assertDontSee('aria-label="Bold"', false)
        ->assertSee('whitespace-pre-wrap', false);
});

test('the editor shows the validation errors with their position', function () {
    $this->withViewErrors(['body' => ['Line 2, column 3: Unknown variable "payer.name".']])
        ->blade('<x-ui.template-editor :schema="$schema" name="body" />', ['schema' => editorSchema()])
        ->assertSee('Line 2, column 3: Unknown variable &quot;payer.name&quot;.', false)
        ->assertSee('aria-invalid="true"', false)
        ->assertSee('aria-describedby="template-body-errors"', false)
        ->assertSee('ring-danger', false);
});

test('the editor works with wire model', function () {
    $component = new class extends Component
    {
        public string $body = '';

        public function save(): void
        {
            $this->validate(['body' => ['required', new ValidTemplate(editorSchema())]]);
        }

        public function render(): string
        {
            return <<<'BLADE'
                <div>
                    <x-ui.template-editor :schema="editorSchema()" wire:model="body" label="Body" />
                </div>
                BLADE;
        }
    };

    Livewire::test($component)
        ->assertSeeHtml('wire:model="body"')
        ->assertSeeHtml('x-modelable="value"')
        ->assertSeeHtml('id="template-body"')
        ->set('body', "Hi\n{% payer.name %}")
        ->call('save')
        ->assertHasErrors('body')
        ->assertSee('Line 2, column 1: Unknown variable')
        ->set('body', 'Invoice {% invoice.number %}')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDontSee('Unknown variable');
});

test('the invoice e-mail edits its subject on one line and its body as rich text', function () {
    $this->seed(LookupSeeder::class);

    Livewire::actingAs(User::factory()->withModules([Module::INVOICES])->create())
        ->test(InvoiceRecurrenceForm::class)
        ->assertSeeHtmlInOrder([
            'data-template-editor="email_subject"', 'data-template-editor-mode="inline"',
            'data-template-editor="email_body"', 'data-template-editor-mode="rich"',
        ]);
});

test('notification titles are edited on one line and bodies as rich text, one editor per tab', function () {
    $this->seed(LookupSeeder::class);
    app(NotificationProvisioner::class)->provisionAll();

    Livewire::actingAs(User::factory()->superAdmin()->create())
        ->test(NotificationTemplateEditor::class, ['notificationType' => NotificationType::query()->where('slug', NotificationType::MODULE_ENABLED)->sole()])
        ->call('selectTab', 'whatsapp', 'en')
        ->assertSeeHtmlInOrder([
            'data-template-editor="templates.whatsapp.en.title"', 'data-template-editor-mode="inline"', 'wire:key="editor-whatsapp-en-title"',
            'data-template-editor="templates.whatsapp.en.body"', 'data-template-editor-mode="rich"', 'wire:key="editor-whatsapp-en-body"',
        ]);
});

test('a whatsapp message is edited as plain text', function () {
    $this->seed(LookupSeeder::class);
    FakeEvolutionApi::install();

    $owner = User::factory()->withModules([Module::WHATSAPP])->create();
    WhatsappAccount::factory()->open()->for($owner)->create();

    Livewire::actingAs($owner)
        ->test(MessageForm::class)
        ->assertSeeHtml('data-template-editor="body"')
        ->assertSeeHtml('data-template-editor-mode="plain"');
});
