<?php

use App\Models\Currency;
use App\Models\DeliveryStatus;
use App\Models\Invoice;
use App\Models\InvoiceDateRule;
use App\Models\InvoiceDelivery;
use App\Models\InvoiceDeliveryMode;
use App\Models\InvoiceDueDateRule;
use App\Models\InvoiceRecipient;
use App\Models\InvoiceRecurrence;
use App\Models\InvoiceRecurrenceRecipient;
use App\Models\InvoiceStatus;
use App\Models\Issuer;
use App\Models\Payer;
use App\Models\Recurrence;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\ReminderOccurrence;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappConnectionStatus;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use App\Models\WhatsappTargetType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

test('module factories create valid records', function (string $modelClass) {
    /** @var class-string<Model> $modelClass */
    $records = $modelClass::factory()->count(3)->create();

    expect($records)->toHaveCount(3)
        ->and($modelClass::query()->count())->toBe(3)
        ->and($records->every(fn (Model $record) => $record->exists))->toBeTrue();
})->with([
    Payer::class,
    Issuer::class,
    InvoiceRecurrence::class,
    Invoice::class,
    InvoiceDelivery::class,
    WhatsappAccount::class,
    WhatsappTarget::class,
    WhatsappMessage::class,
    WhatsappDelivery::class,
    ShoppingList::class,
    ShoppingListItem::class,
    Reminder::class,
    ReminderOccurrence::class,
]);

test('invoice factory states', function () {
    $recurrence = InvoiceRecurrence::factory()->requiresReview()->withRecipients(3)->create();
    $generated = Invoice::factory()->create();
    $oneOff = Invoice::factory()->oneOff()->create();
    $secondOfTheMonth = Invoice::factory()->create();

    expect(InvoiceRecurrence::factory()->create()->deliveryMode->slug)->toBe(InvoiceDeliveryMode::AUTOMATIC)
        ->and(InvoiceRecurrence::factory()->automatic()->create()->deliveryMode->slug)->toBe(InvoiceDeliveryMode::AUTOMATIC)
        ->and($recurrence->deliveryMode->slug)->toBe(InvoiceDeliveryMode::REQUIRES_REVIEW)
        ->and($recurrence->recipients)->toHaveCount(3)
        ->and($recurrence->recurrence->frequency->slug)->toBe(RecurrenceFrequency::MONTHLY)
        ->and($generated->status->slug)->toBe(InvoiceStatus::DRAFT)
        ->and($generated->recurrence)->not->toBeNull()
        ->and($generated->payer_id)->toBe($generated->recurrence->payer_id)
        ->and($generated->payer_name)->toBe($generated->recurrence->payer->name)
        ->and($generated->issuer_email)->toBe($generated->recurrence->issuer->email)
        ->and($generated->amount)->toBe($generated->recurrence->amount)
        ->and($generated->number)->toBe(now()->format('Ym').'-001')
        ->and($secondOfTheMonth->number)->toBe(now()->format('Ym').'-003')
        ->and($oneOff->recurrence)->toBeNull()
        ->and($oneOff->period_key)->toBeNull()
        ->and($oneOff->payer)->not->toBeNull()
        ->and(Invoice::factory()->pendingReview()->create()->isPendingReview())->toBeTrue()
        ->and(Invoice::factory()->sent()->create()->isSent())->toBeTrue()
        ->and(Invoice::factory()->failed()->create()->status->slug)->toBe(InvoiceStatus::FAILED)
        ->and(Invoice::factory()->cancelled()->create()->isCancelled())->toBeTrue();
});

test('whatsapp factory states', function () {
    $once = WhatsappMessage::factory()->once()->create();
    $recurring = WhatsappMessage::factory()->recurring()->withTargets(2)->create();
    $cancelled = WhatsappMessage::factory()->cancelled()->create();

    expect(WhatsappAccount::factory()->create()->isOpen())->toBeTrue()
        ->and(WhatsappAccount::factory()->open()->create()->isOpen())->toBeTrue()
        ->and(WhatsappAccount::factory()->closed()->create()->status->slug)->toBe(WhatsappConnectionStatus::CLOSED)
        ->and(WhatsappAccount::factory()->connecting()->create()->status->slug)->toBe(WhatsappConnectionStatus::CONNECTING)
        ->and(WhatsappTarget::factory()->contact()->create()->type->slug)->toBe(WhatsappTargetType::CONTACT)
        ->and(WhatsappTarget::factory()->group()->create()->jid)->toEndWith('@g.us')
        ->and(WhatsappTarget::factory()->manual()->create()->is_synced)->toBeFalse()
        ->and(WhatsappMessage::factory()->create()->isOnce())->toBeTrue()
        ->and($once->isOnce())->toBeTrue()
        ->and($once->recurrence)->toBeInstanceOf(Recurrence::class)
        ->and($recurring->isOnce())->toBeFalse()
        ->and($recurring->recurrence->frequency->slug)->toBe(RecurrenceFrequency::DAILY)
        ->and($recurring->targets)->toHaveCount(2)
        ->and($recurring->targets->every(fn (WhatsappTarget $target) => $target->whatsapp_account_id === $recurring->whatsapp_account_id))->toBeTrue()
        ->and($recurring->account->user_id)->toBe($recurring->user_id)
        ->and($cancelled->isOnce())->toBeTrue()
        ->and($cancelled->isCancelled())->toBeTrue()
        ->and($cancelled->recurrence->next_run_at)->toBeNull()
        ->and(WhatsappDelivery::factory()->create()->source->slug)->toBe(WhatsappDeliverySource::MANUAL)
        ->and(WhatsappDelivery::factory()->scheduled($recurring)->create()->target->is($recurring->targets->first()))->toBeTrue()
        ->and(WhatsappDelivery::factory()->sent()->create()->status->slug)->toBe(DeliveryStatus::SENT)
        ->and(WhatsappDelivery::factory()->failed()->create()->status->slug)->toBe(DeliveryStatus::FAILED);
});

test('shopping and reminder factory states', function () {
    $dated = Reminder::factory()->dated()->create();
    $undated = Reminder::factory()->undated()->create();
    $recurring = Reminder::factory()->recurring()->create();
    $checked = ShoppingListItem::factory()->checked()->create();

    expect(ShoppingList::factory()->archived()->create()->archived_at)->not->toBeNull()
        ->and(ShoppingList::query()->notArchived()->count())->toBe(1)
        ->and($checked->isChecked())->toBeTrue()
        ->and($checked->checkedBy->is($checked->createdBy))->toBeTrue()
        ->and($dated->recurrence->frequency->slug)->toBe(RecurrenceFrequency::ONCE)
        ->and($dated->occurrences)->toHaveCount(1)
        ->and($dated->occurrences->first()->due_at->equalTo($dated->recurrence->starts_at))->toBeTrue()
        ->and($undated->recurrence)->toBeNull()
        ->and($undated->occurrences->first()->due_at)->toBeNull()
        ->and($recurring->recurrence->frequency->slug)->toBe(RecurrenceFrequency::DAILY)
        ->and($recurring->occurrences->first()->due_at->equalTo($recurring->recurrence->next_run_at))->toBeTrue()
        ->and(ReminderOccurrence::query()->pending()->count())->toBe(3)
        ->and(ReminderOccurrence::factory()->completed()->create()->completedBy)->toBeInstanceOf(User::class)
        ->and(ReminderOccurrence::query()->overdue()->count())->toBe(0)
        ->and(ReminderOccurrence::factory()->overdue()->create()->id)->toBe(ReminderOccurrence::query()->overdue()->sole()->id);
});

/**
 * One record of every module model, with every relation filled in.
 *
 * @return array<class-string<Model>, Model>
 */
function moduleModelGraph(): array
{
    $invoiceRecurrence = InvoiceRecurrence::factory()->requiresReview()->withRecipients(2)->create();
    $invoice = Invoice::factory()->sent()->create([
        'invoice_recurrence_id' => $invoiceRecurrence->id,
        'approved_by_id' => User::factory(),
        'created_by_id' => User::factory(),
    ]);
    $invoice->recipients()->create(['email' => 'billing@acme.test']);
    $invoiceDelivery = InvoiceDelivery::factory()->for($invoice)->create(['triggered_by_id' => User::factory()]);

    $message = WhatsappMessage::factory()->recurring()->withTargets(2)->create();
    $whatsappDelivery = WhatsappDelivery::factory()->scheduled($message)->sent()->create(['triggered_by_id' => $message->user_id]);

    $item = ShoppingListItem::factory()->checked()->create();

    $reminder = Reminder::factory()->recurring()->create();
    $occurrence = ReminderOccurrence::factory()->completed()->for($reminder)->create(['due_at' => now()->subDay()]);

    return [
        Payer::class => $invoiceRecurrence->payer,
        Issuer::class => $invoiceRecurrence->issuer,
        InvoiceRecurrence::class => $invoiceRecurrence,
        InvoiceRecurrenceRecipient::class => $invoiceRecurrence->recipients->first(),
        Invoice::class => $invoice,
        InvoiceRecipient::class => $invoice->recipients()->first(),
        InvoiceDelivery::class => $invoiceDelivery,
        WhatsappAccount::class => $message->account,
        WhatsappTarget::class => $message->targets->first(),
        WhatsappMessage::class => $message,
        WhatsappDelivery::class => $whatsappDelivery,
        ShoppingList::class => $item->list,
        ShoppingListItem::class => $item,
        Reminder::class => $reminder,
        ReminderOccurrence::class => $occurrence,
    ];
}

test('relationships resolve for every module model', function (string $modelClass, array $relations) {
    $model = moduleModelGraph()[$modelClass]->fresh();

    foreach ($relations as $relation => $relatedClass) {
        $query = $model->{$relation}();
        $related = $model->{$relation};

        expect($query)->toBeInstanceOf(Relation::class, "{$modelClass}::{$relation}()")
            ->and($query->getRelated())->toBeInstanceOf($relatedClass, "{$modelClass}::{$relation}()");

        if ($related instanceof Collection) {
            expect($related)->not->toBeEmpty("{$modelClass}::{$relation} is empty")
                ->and($related->every(fn (Model $record) => $record instanceof $relatedClass))->toBeTrue();
        } else {
            expect($related)->toBeInstanceOf($relatedClass, "{$modelClass}::{$relation}");
        }
    }
})->with([
    'payer' => [Payer::class, ['createdBy' => User::class, 'invoiceRecurrences' => InvoiceRecurrence::class, 'invoices' => Invoice::class]],
    'issuer' => [Issuer::class, ['createdBy' => User::class, 'invoiceRecurrences' => InvoiceRecurrence::class, 'invoices' => Invoice::class]],
    'invoice recurrence' => [InvoiceRecurrence::class, [
        'payer' => Payer::class, 'issuer' => Issuer::class, 'currency' => Currency::class, 'dateRule' => InvoiceDateRule::class,
        'dueDateRule' => InvoiceDueDateRule::class, 'deliveryMode' => InvoiceDeliveryMode::class,
        'recipients' => InvoiceRecurrenceRecipient::class, 'invoices' => Invoice::class, 'createdBy' => User::class,
        'recurrence' => Recurrence::class,
    ]],
    'invoice recurrence recipient' => [InvoiceRecurrenceRecipient::class, ['invoiceRecurrence' => InvoiceRecurrence::class]],
    'invoice' => [Invoice::class, [
        'recurrence' => InvoiceRecurrence::class, 'status' => InvoiceStatus::class, 'currency' => Currency::class,
        'payer' => Payer::class, 'issuer' => Issuer::class, 'recipients' => InvoiceRecipient::class,
        'deliveries' => InvoiceDelivery::class, 'approvedBy' => User::class, 'createdBy' => User::class,
    ]],
    'invoice recipient' => [InvoiceRecipient::class, ['invoice' => Invoice::class]],
    'invoice delivery' => [InvoiceDelivery::class, ['invoice' => Invoice::class, 'status' => DeliveryStatus::class, 'triggeredBy' => User::class]],
    'whatsapp account' => [WhatsappAccount::class, [
        'user' => User::class, 'status' => WhatsappConnectionStatus::class, 'targets' => WhatsappTarget::class,
        'messages' => WhatsappMessage::class, 'deliveries' => WhatsappDelivery::class,
    ]],
    'whatsapp target' => [WhatsappTarget::class, ['account' => WhatsappAccount::class, 'type' => WhatsappTargetType::class, 'messages' => WhatsappMessage::class]],
    'whatsapp message' => [WhatsappMessage::class, [
        'user' => User::class, 'account' => WhatsappAccount::class, 'targets' => WhatsappTarget::class,
        'deliveries' => WhatsappDelivery::class, 'recurrence' => Recurrence::class,
    ]],
    'whatsapp delivery' => [WhatsappDelivery::class, [
        'account' => WhatsappAccount::class, 'target' => WhatsappTarget::class, 'message' => WhatsappMessage::class,
        'source' => WhatsappDeliverySource::class, 'status' => DeliveryStatus::class, 'triggeredBy' => User::class,
    ]],
    'shopping list' => [ShoppingList::class, ['items' => ShoppingListItem::class, 'createdBy' => User::class]],
    'shopping list item' => [ShoppingListItem::class, ['list' => ShoppingList::class, 'checkedBy' => User::class, 'createdBy' => User::class]],
    'reminder' => [Reminder::class, ['occurrences' => ReminderOccurrence::class, 'createdBy' => User::class, 'recurrence' => Recurrence::class]],
    'reminder occurrence' => [ReminderOccurrence::class, ['reminder' => Reminder::class, 'completedBy' => User::class]],
]);
