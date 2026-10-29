<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\InvoiceDateRule;
use App\Models\InvoiceDeliveryMode;
use App\Models\InvoiceDueDateRule;
use App\Models\InvoiceRecurrence;
use App\Models\Issuer;
use App\Models\Locale;
use App\Models\Payer;
use App\Models\Recurrence;
use App\Models\User;
use Database\Factories\Concerns\ResolvesLookups;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceRecurrence>
 */
class InvoiceRecurrenceFactory extends Factory
{
    use ResolvesLookups;

    /**
     * An English USD invoice sent automatically on the execution day, due 10 days
     * later, without recipients, in the household workspace unless told
     * another, billing a new payer and issuer of its workspace. Created with
     * a monthly recurrence on day 1 unless one was given through `has()`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => fn (): int => WorkspaceFactory::household()->id,
            'name' => fake()->words(2, true),
            'payer_id' => fn (array $attributes) => Payer::factory()->state(['workspace_id' => $attributes['workspace_id']]),
            'issuer_id' => fn (array $attributes) => Issuer::factory()->state(['workspace_id' => $attributes['workspace_id']]),
            'currency_id' => fn (): int => static::lookupId(Currency::class, Currency::USD, ['name' => 'American dollar', 'symbol' => '$']),
            'amount' => fake()->randomFloat(2, 100, 10000),
            'service_description' => fake()->sentence(),
            'email_subject' => 'Invoice {% invoice.number %}',
            'email_body' => "Hello,\n\nPlease find attached the invoice {% invoice.number %}.",
            'invoice_date_rule_id' => fn (): int => static::lookupId(InvoiceDateRule::class, InvoiceDateRule::EXECUTION_DAY),
            'invoice_date_day' => null,
            'invoice_due_date_rule_id' => fn (): int => static::lookupId(InvoiceDueDateRule::class, InvoiceDueDateRule::DAYS_AFTER_ISSUE, ['name' => 'Days after issue', 'requires_value' => true]),
            'due_date_value' => 10,
            'invoice_delivery_mode_id' => fn (): int => static::lookupId(InvoiceDeliveryMode::class, InvoiceDeliveryMode::AUTOMATIC),
            'locale_id' => fn (): int => static::lookupId(Locale::class, Locale::EN, ['name' => 'English']),
            'created_by_id' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (InvoiceRecurrence $invoiceRecurrence): void {
            if ($invoiceRecurrence->recurrence()->doesntExist()) {
                Recurrence::factory()->monthly(1)->for($invoiceRecurrence, 'schedulable')->create();
            }
        });
    }

    /**
     * Each invoice is sent as soon as it is generated.
     */
    public function automatic(): static
    {
        return $this->deliveryMode(InvoiceDeliveryMode::AUTOMATIC);
    }

    /**
     * Each invoice waits in `pending_review` for approval (US-6.6).
     */
    public function requiresReview(): static
    {
        return $this->deliveryMode(InvoiceDeliveryMode::REQUIRES_REVIEW);
    }

    /**
     * With `$count` distinct recipient addresses.
     */
    public function withRecipients(int $count = 2): static
    {
        return $this->afterCreating(function (InvoiceRecurrence $invoiceRecurrence) use ($count): void {
            foreach (range(1, $count) as $index) {
                $invoiceRecurrence->recipients()->create(['email' => "billing{$index}.".fake()->unique()->safeEmail()]);
            }
        });
    }

    private function deliveryMode(string $slug): static
    {
        return $this->state(fn (array $attributes) => [
            'invoice_delivery_mode_id' => static::lookupId(InvoiceDeliveryMode::class, $slug),
        ]);
    }
}
