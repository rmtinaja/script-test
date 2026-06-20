<?php

namespace App\Http\Controllers;

use App\Services\ReplicaRestoreService;
use App\Services\RestoreState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Browser-facing trigger for the replica restore (routes/web.php).
 *
 * The restore of a ~4GB backup runs for minutes, so we never do it inside
 * the request: start() spawns a DETACHED `php artisan replica:restore`
 * worker and returns immediately. The page polls RestoreState and shows the
 * outcome when the worker finishes.
 */
class ReplicaWebController extends Controller
{
    public function __construct(
        private readonly ReplicaRestoreService $service,
    ) {}

    /** GET /replica — status + confirm form. */
    public function index(): View
    {
        return view('replica', [
            'status' => $this->service->status(),
            'run'    => RestoreState::read(),
        ]);
    }

    /** POST /replica/restore — validate, then kick off the detached worker. */
    public function start(Request $request): RedirectResponse
    {
        $cfg = config('saerp_replica');

        if (! ($cfg['enabled'] ?? false)) {
            return back()->with('error', 'Restore is disabled (SAERP_REPLICA_RESTORE_ENABLED=false).');
        }

        // Step 1 of the two-step: must type CONFIRM exactly.
        if (strtoupper(trim((string) $request->input('confirm'))) !== 'CONFIRM') {
            return back()->with('error', 'Type CONFIRM (in caps) to proceed.');
        }

        // Step 2: must present the admin secret (= REPLICA_SYNC_API_KEY).
        $secret = (string) $cfg['api_key'];
        if ($secret === '' || ! hash_equals($secret, (string) $request->input('secret'))) {
            return back()->with('error', 'Wrong admin key.');
        }

        if (RestoreState::isRunning()) {
            return back()->with('error', 'A restore is already running.');
        }

        // Pre-flight the cheap guards so the user gets an immediate error
        // instead of a silently-failing background worker.
        $st = $this->service->status();
        if (! $st['backup_present']) {
            return back()->with('error', "Backup file not found: {$st['backup_file']}");
        }

        RestoreState::markRunning();

        // Detached spawn: `start /B` launches the worker in its own process
        // that outlives this request. Symfony only waits on the `start`
        // launcher (which exits instantly), not the restore itself.
        $php     = (string) $cfg['php_bin'];
        $artisan = base_path('artisan');
        $cmd     = sprintf('start /B "" "%s" "%s" replica:restore --force', $php, $artisan);

        try {
            Process::fromShellCommandline($cmd, base_path())->setTimeout(60)->run();
        } catch (\Throwable $e) {
            RestoreState::markFailed('Could not start worker: ' . $e->getMessage());
            Log::error('saerp.replica.spawn.failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Could not start the restore worker: ' . $e->getMessage());
        }

        Log::warning('saerp.replica.restore.started', ['via' => 'web', 'ip' => $request->ip()]);

        return redirect('/replica')->with('ok', 'Restore started — this page will update when it finishes.');
    }
}
