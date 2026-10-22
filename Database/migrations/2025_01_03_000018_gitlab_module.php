<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitLab 连接器：项目投影与 Webhook 事件投影（结构迁移，与数据迁移成对）
 *
 * 凭据不落本模块：token 只存在 Integration 的 integration_connections.credentials
 * （encrypted:array）；本模块两张表都不含 token、也不保存 webhook 原始 payload。
 *
 * 幂等设计（模块迁移可能在任何环境的既有库上重放）：
 *   - hasTable 守卫：下游项目若已手工建过同名表，跳过建表
 * RBAC 权限注册见同块 gitlab_module_data 迁移。
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createProjectsTable();
        $this->createWebhookEventsTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('gitlab_webhook_events');
        Schema::dropIfExists('gitlab_projects');
    }

    private function createProjectsTable(): void
    {
        if (Schema::hasTable('gitlab_projects')) {
            return;
        }

        Schema::create('gitlab_projects', function (Blueprint $table) {
            $table->unsignedBigInteger('gitlab_project_id')->primary()->comment('IdGenerator 全局ID');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('connection_id')->comment('integration_connections.connection_id');
            $table->unsignedBigInteger('project_id')->nullable()->comment('本地 project 模块ID（采纳后回填）');
            $table->string('external_project_id', 100)->comment('GitLab project id（幂等键）');
            $table->string('path_with_namespace', 255)->comment('group/project');
            $table->string('name', 255)->nullable();
            $table->string('default_branch', 255)->nullable();
            $table->string('visibility', 30)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable()->comment('非敏感投影：MR/pipeline/tag 最新状态');
            $table->timestamps();

            $table->unique(['tenant_id', 'external_project_id'], 'gitlab_projects_tenant_external_unique');
            $table->index(['tenant_id', 'project_id'], 'gitlab_projects_tenant_project_index');
        });
    }

    private function createWebhookEventsTable(): void
    {
        if (Schema::hasTable('gitlab_webhook_events')) {
            return;
        }

        Schema::create('gitlab_webhook_events', function (Blueprint $table) {
            $table->unsignedBigInteger('gitlab_event_id')->primary()->comment('IdGenerator 全局ID');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('inbox_event_id')->comment('integration_inbox_events.event_id');
            $table->string('external_project_id', 100)->nullable();
            $table->unsignedBigInteger('project_id')->nullable()->comment('本地 project 模块ID');
            $table->string('event_type', 100)->comment('gitlab.merge_request|gitlab.pipeline|gitlab.tag_push|gitlab.push');
            $table->string('object_kind', 50)->nullable();
            $table->unsignedBigInteger('mr_iid')->nullable();
            $table->unsignedBigInteger('pipeline_id')->nullable();
            $table->string('status', 30)->nullable();
            $table->json('task_keys')->nullable()->comment('提取出的任务号，去重且最多 10 个');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('error', 1000)->nullable()->comment('脱敏后的失败摘要');
            $table->unsignedInteger('replay_count')->default(0)->comment('重放次数');
            $table->timestamp('replayed_at')->nullable()->comment('最近一次重放时间');
            $table->timestamp('created_at')->nullable();

            $table->unique('inbox_event_id', 'gitlab_webhook_events_inbox_unique');
            $table->index(['tenant_id', 'external_project_id', 'received_at'], 'gitlab_webhook_events_project_received_index');
            $table->index(['tenant_id', 'event_type', 'status'], 'gitlab_webhook_events_type_status_index');
        });
    }
};
