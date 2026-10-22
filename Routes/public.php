<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Gitlab\Http\Controllers\GitlabWebhookController;

/*
|--------------------------------------------------------------------------
| GitLab 连接器 — 公开路由
|--------------------------------------------------------------------------
| 无需登录：webhook 由 GitLab 服务端发起。基类仅附加 api group，前缀 api/v1。
| 准入由控制器内 X-Gitlab-Token 常量时间校验 + 事件类型白名单 + body 上限负责，
| 额外挂 throttle 防止验签暴力尝试。
*/

Route::prefix('gitlab')->group(function () {
    Route::post('webhook/{connection}', GitlabWebhookController::class)
        ->middleware('throttle:120,1');
});
