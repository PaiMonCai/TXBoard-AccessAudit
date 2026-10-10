<?php

use Illuminate\Support\Facades\Route;
use Plugin\AccessAudit\Http\Controllers\ReportController;

// 节点上报通道：复用 Xboard 原版节点认证（server_token + node_id / machine token）。
// node_id 从认证属性 node_info 取，不信上报方自报（防伪造）。
Route::middleware(['api', \App\Http\Middleware\ServerV2::class])
    ->prefix('/api/v1/plugin/access-audit')
    ->group(function () {
        Route::post('/report', [ReportController::class, 'report']);
        Route::get('/rules', [ReportController::class, 'rules']);
    });
