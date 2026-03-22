<?php

use Illuminate\Support\Facades\Route;

// API routes (Phase 1 — placeholder; expanded in later phases)
Route::middleware('auth')->group(function () {
    Route::get('/user', fn (\Illuminate\Http\Request $r) => $r->user())->name('api.user');
});
