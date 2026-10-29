<?php

namespace App\Services\Invoices;

use App\Models\InvoiceNumberSequence;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reserves the next invoice number of a workspace (US-6.5): `YYYYMM-NNN`,
 * the month of the issue date and its counter zero-padded to 3 digits,
 * starting again at `001` every month. Each workspace counts on its own, so
 * two workspaces may both have a `202610-001`.
 *
 * The `invoice_number_sequences` row of the workspace and month is read with
 * `SELECT ... FOR UPDATE` inside a transaction (created first when there is
 * none, `ON CONFLICT DO NOTHING`), so concurrent runs of a workspace wait for
 * each other and never get the same number. A number is never given back: a cancelled
 * invoice keeps it. Called inside a larger transaction, the reservation is
 * rolled back with it.
 */
final class InvoiceNumberGenerator
{
    /**
     * The workspace's next number of the issue date's month, already
     * reserved.
     */
    public static function next(Workspace|int $workspace, CarbonImmutable $issueDate): string
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;
        $yearMonth = $issueDate->format('Ym');

        return DB::transaction(function () use ($workspaceId, $yearMonth): string {
            $sequence = self::lockedSequence($workspaceId, $yearMonth);

            if ($sequence === null) {
                InvoiceNumberSequence::query()->insertOrIgnore([
                    'workspace_id' => $workspaceId,
                    'year_month' => $yearMonth,
                    'last_number' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $sequence = self::lockedSequence($workspaceId, $yearMonth)
                    ?? throw new RuntimeException("Invoice number sequence [{$workspaceId}/{$yearMonth}] could not be created.");
            }

            $sequence->last_number++;
            $sequence->save();

            return self::format($yearMonth, $sequence->last_number);
        });
    }

    /**
     * `YYYYMM-NNN`.
     */
    public static function format(string $yearMonth, int $sequence): string
    {
        return sprintf('%s-%03d', $yearMonth, $sequence);
    }

    /**
     * The month and the counter of a number (`['202610', 1]`).
     *
     * @return array{0: string, 1: int}
     */
    public static function parse(string $number): array
    {
        [$yearMonth, $sequence] = explode('-', $number, 2);

        return [$yearMonth, (int) $sequence];
    }

    private static function lockedSequence(int $workspaceId, string $yearMonth): ?InvoiceNumberSequence
    {
        return InvoiceNumberSequence::query()
            ->where('workspace_id', $workspaceId)
            ->where('year_month', $yearMonth)
            ->lockForUpdate()
            ->first();
    }
}
