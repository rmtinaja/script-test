<?php

namespace App\Console\Commands;

use App\Services\ReplicaRestoreService;
use App\Services\RestoreState;
use Illuminate\Console\Command;
use Throwable;

/**
 * Manual / CLI entry point for the same restore the Discord bot triggers.
 *
 *   php artisan replica:restore            # prompts for confirmation
 *   php artisan replica:restore --force    # no prompt (for scripts/cron)
 *   php artisan replica:restore --status   # show config + backup state, do nothing
 */
class RestoreReplica extends Command
{
    protected $signature = 'replica:restore
                            {--force  : Skip the interactive confirmation prompt}
                            {--status : Print status (connection, backup) and exit without restoring}';

    protected $description = 'Restore the SAERP replica from the configured backup .sql, snapshotting current data first.';

    public function handle(ReplicaRestoreService $service): int
    {
        if ($this->option('status')) {
            foreach ($service->status() as $k => $v) {
                $this->line(sprintf('  %-16s %s', $k, var_export($v, true)));
            }
            return self::SUCCESS;
        }

        $s = $service->status();
        $this->warn('This will OVERWRITE the live replica:');
        $this->line("  database : {$s['database']} @ {$s['host']}");
        $this->line("  from     : {$s['backup_file']}" . ($s['backup_present'] ? '' : '  (NOT FOUND)'));
        $this->line('  snapshot : ' . ($s['snapshot_first'] ? "yes → {$s['snapshot_dir']}" : 'NO'));

        if (! $this->option('force') && ! $this->confirm('Proceed with the restore?', false)) {
            $this->info('Aborted — nothing changed.');
            return self::SUCCESS;
        }

        RestoreState::markRunning();

        try {
            $r = $service->restore($this->option('force') ? 'web' : 'cli');
            RestoreState::markSuccess($r);
            $this->info("✓ Restored {$r['database']} from {$r['backup_file']} in {$r['duration_sec']}s.");
            if (! empty($r['snapshot_file'])) {
                $this->line("  pre-restore snapshot: {$r['snapshot_file']}");
            }
            return self::SUCCESS;
        } catch (Throwable $e) {
            RestoreState::markFailed($e->getMessage());
            $this->error('✗ ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
