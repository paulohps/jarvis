<?php

use App\Models\Invoice;
use App\Models\InvoiceNumberSequence;
use App\Models\Workspace;
use App\Services\Invoices\InvoiceNumberGenerator;
use App\Support\FriendlyId;
use Carbon\CarbonImmutable;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\DB;

test('first invoice of the month is 001', function () {
    $workspace = WorkspaceFactory::household();

    expect(InvoiceNumberGenerator::next($workspace, CarbonImmutable::parse('2026-10-01')))->toBe('202610-001')
        ->and(InvoiceNumberGenerator::next($workspace, CarbonImmutable::parse('2026-10-31')))->toBe('202610-002')
        ->and(InvoiceNumberSequence::query()->where('workspace_id', $workspace->id)->where('year_month', '202610')->value('last_number'))->toBe(2);
});

test('sequence resets each month', function () {
    $workspace = WorkspaceFactory::household();

    InvoiceNumberGenerator::next($workspace, CarbonImmutable::parse('2026-10-01'));
    InvoiceNumberGenerator::next($workspace, CarbonImmutable::parse('2026-10-15'));

    expect(InvoiceNumberGenerator::next($workspace, CarbonImmutable::parse('2026-11-01')))->toBe('202611-001')
        ->and(InvoiceNumberGenerator::next($workspace, CarbonImmutable::parse('2027-01-01')))->toBe('202701-001')
        ->and(InvoiceNumberGenerator::next($workspace, CarbonImmutable::parse('2026-10-20')))->toBe('202610-003');
});

test('each workspace counts its own numbers', function () {
    $household = WorkspaceFactory::household();
    $other = Workspace::factory()->create();

    InvoiceNumberGenerator::next($household, CarbonImmutable::parse('2026-10-01'));
    InvoiceNumberGenerator::next($household, CarbonImmutable::parse('2026-10-02'));

    expect(InvoiceNumberGenerator::next($other, CarbonImmutable::parse('2026-10-03')))->toBe('202610-001')
        ->and(InvoiceNumberGenerator::next($household, CarbonImmutable::parse('2026-10-04')))->toBe('202610-003')
        ->and(InvoiceNumberGenerator::next($other, CarbonImmutable::parse('2026-10-05')))->toBe('202610-002');
});

test('concurrent generation never duplicates numbers', function () {
    // Real concurrency: forked processes, each on its own connection outside
    // the test transaction, all taking numbers of the same month at once.
    $connection = 'invoice_number_concurrency';
    config(["database.connections.{$connection}" => config('database.connections.'.config('database.default'))]);

    $processes = 4;
    $numbersPerProcess = 5;
    $yearMonth = '209912';
    $outputs = [];
    $children = [];

    // The children run outside the test transaction, so their workspace is
    // committed for real too.
    $workspaceId = DB::connection($connection)->table('workspaces')->insertGetId([
        'friendly_id' => FriendlyId::generate(Workspace::idPrefix()),
        'name' => 'Concurrency',
        'color' => 'blue',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Each child opens a connection of its own, never the parent's socket.
    DB::purge($connection);

    try {
        foreach (range(1, $processes) as $index) {
            $output = tempnam(sys_get_temp_dir(), 'invoice-numbers-');
            $outputs[] = $output;
            $pid = pcntl_fork();

            if ($pid === 0) {
                // The child never touches the parent's connection and never
                // runs shutdown code: it writes what it got and is killed.
                DB::setDefaultConnection($connection);

                try {
                    $numbers = [];

                    foreach (range(1, $numbersPerProcess) as $ignored) {
                        $numbers[] = InvoiceNumberGenerator::next($workspaceId, CarbonImmutable::parse('2099-12-10'));
                    }

                    file_put_contents($output, implode("\n", $numbers));
                } catch (Throwable $exception) {
                    file_put_contents($output, 'ERROR '.$exception->getMessage());
                }

                posix_kill(posix_getpid(), SIGKILL);
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $numbers = collect($outputs)->flatMap(fn (string $output): array => explode("\n", (string) file_get_contents($output)));

        expect($numbers->filter(fn (string $line): bool => str_starts_with($line, 'ERROR'))->all())->toBe([])
            ->and($numbers)->toHaveCount($processes * $numbersPerProcess)
            ->and($numbers->unique())->toHaveCount($processes * $numbersPerProcess)
            ->and($numbers->sort()->values()->all())->toBe(array_map(
                fn (int $sequence): string => InvoiceNumberGenerator::format($yearMonth, $sequence),
                range(1, $processes * $numbersPerProcess),
            ));
    } finally {
        // The children committed for real: clean up outside the test transaction.
        DB::connection($connection)->table('invoice_number_sequences')->where('workspace_id', $workspaceId)->delete();
        DB::connection($connection)->table('workspaces')->delete($workspaceId);
        DB::purge($connection);

        foreach ($outputs as $output) {
            @unlink($output);
        }
    }
});

test('cancelled numbers are not reused', function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'UTC'));

    $cancelled = Invoice::factory()->oneOff()->cancelled()->create();

    expect($cancelled->number)->toBe('202610-001')
        ->and(InvoiceNumberGenerator::next($cancelled->workspace_id, CarbonImmutable::parse('2026-10-02')))->toBe('202610-002');
});
