<?php

use Illuminate\Support\Facades\Route;
use Plugin\AccessAudit\Http\Controllers\AdminController;
use Plugin\AccessAudit\Http\Controllers\AnalyticsController;
use Plugin\AccessAudit\Http\Controllers\DashboardController;

// Legacy bookmarks now land in the native React plugin UI.
Route::middleware(['web'])->group(function () {
    Route::get('/plugin/access-audit', fn () => redirect('/admin/plugins/access_audit/dashboard'));
    Route::get('/plugin/access-audit/insights', fn () => redirect('/admin/plugins/access_audit/analytics'));
});

// Plugin-owned admin APIs keep their stable root paths and use the same
// Sanctum administrator token as the React admin.
Route::middleware(['web', 'admin'])->group(function () {
    Route::get('/plugin/access-audit/stats', [AdminController::class, 'stats']);
    Route::get('/plugin/access-audit/nodes', [AdminController::class, 'nodes']);
    Route::get('/plugin/access-audit/rules', [AdminController::class, 'rules']);
    Route::post('/plugin/access-audit/rules/save', [AdminController::class, 'saveRule']);
    Route::post('/plugin/access-audit/rules/delete', [AdminController::class, 'deleteRule']);

    Route::get('/plugin/access-audit/reports', [AdminController::class, 'reports']);
    Route::get('/plugin/access-audit/logs', [DashboardController::class, 'logs']);
    Route::post('/plugin/access-audit/logs/clear', [DashboardController::class, 'clearLogs']);
    Route::get('/plugin/access-audit/ban-logs', [AdminController::class, 'banLogs']);

    Route::get('/plugin/access-audit/analytics', [AnalyticsController::class, 'data']);

    Route::post('/plugin/access-audit/ban', [AdminController::class, 'ban']);
    Route::post('/plugin/access-audit/unban', [AdminController::class, 'unban']);
});
