<?php

use App\Service\CarrybeeCatchUpService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('carrybee:catch-up {--limit= : Max consignments to check} {--consignment= : Check one consignment id now} {--dry-run : Show what CarryBee reports without changing anything}', function (CarrybeeCatchUpService $catchUp) {
    $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
    $summary = $catchUp->run($limit, $this->option('consignment') ?: null, (bool) $this->option('dry-run'));
    if ($this->option('dry-run')) {
        $this->warn('Dry run: nothing was changed.');
    }

    $this->info("Checked {$summary['checked']}, updated {$summary['updated']}, failed {$summary['failed']}.");
    foreach ($summary['details'] as $row) {
        $line = ($row['consignment_id'] ?? '?') . ' -> ' . (isset($row['events']) ? implode(' | ', $row['events']) : ($row['error'] ?? $row['note'] ?? 'no change'));
        $this->line('  ' . $line);
    }
})->purpose('Ask CarryBee for the current state of quiet consignments and apply it');

Artisan::command('carrybee:reconcile {--account= : platform or vendor-{id}; all accounts when empty} {--refresh : Fetch stored paid invoices again}', function (\App\Service\CarrybeeReconcileService $reconcile) {
    $keys = $this->option('account') ? [$this->option('account')] : collect(app(\App\Service\CarrybeeInvoiceReportService::class)->accounts())->pluck('key')->all();
    foreach ($keys as $key) {
        $r = $reconcile->syncAccount($key, (bool) $this->option('refresh'));
        $changed = collect($r['orders'])->filter(fn ($o) => !empty($o['changes']))->count();
        $flags = collect($r['orders'])->sum(fn ($o) => count($o['flags']));
        $this->info("{$key}: {$r['invoices']} invoices, {$r['lines']} lines fetched, " . count($r['orders']) . " orders checked, {$changed} corrected, {$flags} flagged.");
        if ($r['error']) {
            $this->warn("  {$r['error']}");
        }
    }
})->purpose('Match order payouts with CarryBee final invoices');

// Needs the server cron: * * * * * php /path/to/artisan schedule:run
Schedule::command('carrybee:catch-up')->everyThirtyMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('carrybee:reconcile')->dailyAt('04:30')->withoutOverlapping()->onOneServer();
