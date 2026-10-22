<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Gitlab\Http\Controllers\GitlabConnectionController;
use MultiTenantSaas\Modules\Gitlab\Http\Controllers\GitlabEventController;
use MultiTenantSaas\Modules\Gitlab\Http\Controllers\GitlabPipelineController;
use MultiTenantSaas\Modules\Gitlab\Http\Controllers\GitlabProjectController;

/*
|--------------------------------------------------------------------------
| GitLab 连接器 — 租户管理路由
|--------------------------------------------------------------------------
| 基类附加：api + auth:sanctum + throttle:api + tenant.identify
| + VerifyOperatorTenant + module.enabled，前缀 api/v1。
| 这里先 operator.identify 装载执行身份，再走 capability 统一授权。
*/

Route::prefix('gitlab')->middleware('operator.identify')->group(function () {
    Route::get('connections', [GitlabConnectionController::class, 'index'])
        ->middleware('capability:common.gitlab.connection.view');
    Route::post('connections', [GitlabConnectionController::class, 'store'])
        ->middleware('capability:common.gitlab.connection.manage');
    Route::put('connections/{id}', [GitlabConnectionController::class, 'update'])
        ->middleware('capability:common.gitlab.connection.manage');
    Route::post('connections/{id}/test', [GitlabConnectionController::class, 'test'])
        ->middleware('capability:common.gitlab.connection.manage');
    Route::post('connections/{id}/rotate', [GitlabConnectionController::class, 'rotate'])
        ->middleware('capability:common.gitlab.connection.manage');

    Route::get('projects', [GitlabProjectController::class, 'index'])
        ->middleware('capability:common.gitlab.project.view');
    Route::post('projects', [GitlabProjectController::class, 'store'])
        ->middleware('capability:common.gitlab.project.manage');

    Route::get('events', [GitlabEventController::class, 'index'])
        ->middleware('capability:common.gitlab.event.view');
    Route::post('events/{id}/replay', [GitlabEventController::class, 'replay'])
        ->middleware('capability:common.gitlab.event.replay');

    Route::get('pipelines', [GitlabPipelineController::class, 'index'])
        ->middleware('capability:common.gitlab.pipeline.view');
    Route::get('pipelines/{id}', [GitlabPipelineController::class, 'show'])
        ->middleware('capability:common.gitlab.pipeline.view');
});
