<?php

namespace App\Http\Controllers;

use App\Services\ReplicaRestoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bot-facing endpoints for the SAERP replica sync. Auth is handled by the
 * `bot.api` (X-Api-Key) middleware on the routes.
 */
class ReplicaController extends Controller
{
    public function __construct(
        private readonly ReplicaRestoreService $service,
    ) {}

    /** GET /api/replica/status — read-only; safe to call any time. */
    public function status(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status'  => $this->service->status(),
        ]);
    }

    /**
     * POST /api/replica/restore — DESTRUCTIVE. Snapshots the current replica,
     * then overwrites it from the configured backup .sql.
     */
    public function restore(Request $request): JsonResponse
    {
        // A restore of a large DB can run for minutes; don't let PHP's time
        // limit kill it mid-flight.
        @set_time_limit(0);
        @ignore_user_abort(true);

        $triggeredBy = (string) $request->input('discord_id', '');

        try {
            $result = $this->service->restore($triggeredBy ?: null);

            Log::warning('saerp.replica.restore.done', $result);

            return response()->json([
                'success' => true,
                'result'  => $result,
            ]);
        } catch (Throwable $e) {
            Log::error('saerp.replica.restore.failed', [
                'error'        => $e->getMessage(),
                'triggered_by' => $triggeredBy,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
