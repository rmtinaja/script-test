<?php

use App\Http\Controllers\ReplicaWebController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/replica'));

// SAERP Replica Sync — browser trigger. GET shows status + confirm form;
// POST starts the detached restore worker (two-step: type CONFIRM + admin key).
Route::get('/replica', [ReplicaWebController::class, 'index']);
Route::post('/replica/restore', [ReplicaWebController::class, 'start']);
