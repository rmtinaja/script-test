<?php

use App\Http\Controllers\ReplicaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — SAERP Replica Sync
|--------------------------------------------------------------------------
|
| All routes here are server-to-server (the Discord bot), authenticated by
| the shared X-Api-Key secret (bot.api middleware). There is no user session.
|
*/

Route::middleware('bot.api')->prefix('replica')->group(function () {
    // Lightweight status: is the backup present, where does the connection
    // point, is restore enabled. Safe / read-only — the bot can show this
    // before asking for confirmation.
    Route::get('/status', [ReplicaController::class, 'status']);

    // DESTRUCTIVE: snapshot the current replica, then overwrite it from the
    // configured backup .sql. Guarded by the kill-switch + allowed_databases
    // pin inside ReplicaRestoreService.
    Route::post('/restore', [ReplicaController::class, 'restore']);
});
