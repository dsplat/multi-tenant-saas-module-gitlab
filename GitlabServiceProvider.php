<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab;

use MultiTenantSaas\Access\ResourceRegistry;
use MultiTenantSaas\Authorization\CapabilityDefinition;
use MultiTenantSaas\Context\ExecutionIdentity;
use MultiTenantSaas\Contracts\AuthorizationCapabilityRegistryContract;
use MultiTenantSaas\Contracts\CapabilityRequirementResolverContract;
use MultiTenantSaas\Contracts\ExecutionSubjectStateContract;
use MultiTenantSaas\Contracts\SceneRegistryContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Archive\Contracts\DocProviderContract;
use MultiTenantSaas\Modules\Archive\Contracts\DocProviderRegistryContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Gitlab\Console\Commands\SyncGitlabProjectsCommand;
use MultiTenantSaas\Modules\Gitlab\Services\GitlabConnectionService;
use MultiTenantSaas\Modules\Gitlab\Services\GitlabDocProvider;
use MultiTenantSaas\Modules\Gitlab\Services\Tools\GitlabEventQueryHandler;
use MultiTenantSaas\Modules\Gitlab\Services\Tools\GitlabMrSummaryHandler;
use MultiTenantSaas\Modules\Gitlab\Services\Tools\GitlabPipelineFailureExplainHandler;

/**
 * GitLab 连接器
 *
 * 路由 / 迁移 / 配置由基类 ModuleServiceProvider 按约定自动加载。
 * 本模块只做：连接（落 Integration）、只读投影、webhook、Archive DocProvider。
 * 不持有云效 token、不直连 Yunxiao。
 */
class GitlabServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'gitlab';

    /**
     * 能力点 → 所需权限（gitlab.view 只读 / gitlab.manage 管理）。
     */
    private const CAPABILITY_PERMISSIONS = [
        'connection.view' => 'gitlab.view',
        'connection.manage' => 'gitlab.manage',
        'project.view' => 'gitlab.view',
        'project.manage' => 'gitlab.manage',
        'event.view' => 'gitlab.view',
        'event.replay' => 'gitlab.manage',
        'pipeline.view' => 'gitlab.view',
        'doc.write' => 'gitlab.manage',
    ];

    /**
     * 旧能力点兼容别名（脚手架 common.gitlab.list/read/create/update/delete）。
     */
    private const LEGACY_ALIASES = [
        'list' => 'gitlab.view',
        'read' => 'gitlab.view',
        'create' => 'gitlab.manage',
        'update' => 'gitlab.manage',
        'delete' => 'gitlab.manage',
    ];

    protected function registerModuleBindings(): void {}

    protected function registerModuleCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SyncGitlabProjectsCommand::class]);
        }
    }

    protected function bootModule(): void
    {
        if ($this->app->bound(ExecutionSubjectStateContract::class)) {
            $this->app->make(ExecutionSubjectStateContract::class)->trustService('gitlab.webhook');
        }

        $this->registerAuthorizationCapabilities();
        $this->registerResources();
        $this->registerDocProvider();
        $this->registerTools();
        $this->registerScenes();
    }

    /** 能力定义归模块，permission 映射可由宿主项目收窄。 */
    private function registerAuthorizationCapabilities(): void
    {
        $registry = $this->app->make(AuthorizationCapabilityRegistryContract::class);
        $requirements = $this->app->make(CapabilityRequirementResolverContract::class);

        foreach (self::CAPABILITY_PERMISSIONS as $capability => $permission) {
            $this->registerCapability($registry, 'common.gitlab.' . $capability);
        }

        foreach (self::LEGACY_ALIASES as $alias => $permission) {
            $this->registerCapability($registry, 'common.gitlab.' . $alias);
        }

        // 权限映射保持字面量：AdminConsolePermissionMapTest 静态解析 capability→permission。
        $requirements->register('common.gitlab.connection.view', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'gitlab.view']);
        $requirements->register('common.gitlab.connection.manage', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'gitlab.manage']);
        $requirements->register('common.gitlab.project.view', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'gitlab.view']);
        $requirements->register('common.gitlab.project.manage', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'gitlab.manage']);
        $requirements->register('common.gitlab.event.view', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'gitlab.view']);
        $requirements->register('common.gitlab.event.replay', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'gitlab.manage']);
        $requirements->register('common.gitlab.pipeline.view', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'gitlab.view']);
        $requirements->register('common.gitlab.doc.write', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'gitlab.manage']);
        $requirements->register('common.gitlab.list', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'gitlab.view']);
        $requirements->register('common.gitlab.read', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'gitlab.view']);
        $requirements->register('common.gitlab.create', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'gitlab.manage']);
        $requirements->register('common.gitlab.update', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'gitlab.manage']);
        $requirements->register('common.gitlab.delete', ExecutionIdentity::TYPE_OPERATOR, ['permission' => 'gitlab.manage']);
    }

    private function registerCapability(
        AuthorizationCapabilityRegistryContract $registry,
        string $key,
    ): void {
        $registry->register(new CapabilityDefinition(
            key: $key,
            subjectTypes: [ExecutionIdentity::TYPE_OPERATOR],
            targetScope: CapabilityDefinition::TARGET_TENANT,
            admissionPolicy: [ExecutionIdentity::TYPE_OPERATOR => ['member']],
        ));
    }

    /**
     * 数据资源保护声明：两张投影表都含敏感事件元数据，未知资源 fail-closed。
     */
    private function registerResources(): void
    {
        $registry = $this->app->make(ResourceRegistry::class);

        $registry->register(
            'gitlab_projects',
            requiresPolicy: true,
            sensitive: true,
            filterableColumns: [
                'gitlab_project_id', 'tenant_id', 'connection_id', 'project_id',
                'external_project_id', 'path_with_namespace', 'visibility', 'last_synced_at',
            ],
            returnableColumns: [
                'gitlab_project_id', 'connection_id', 'project_id', 'external_project_id',
                'path_with_namespace', 'name', 'default_branch', 'visibility',
                'last_synced_at', 'metadata', 'created_at', 'updated_at',
            ],
            supportedActions: ['read', 'list'],
        );

        $registry->register(
            'gitlab_webhook_events',
            requiresPolicy: true,
            sensitive: true,
            filterableColumns: [
                'gitlab_event_id', 'tenant_id', 'connection_id', 'inbox_event_id',
                'external_project_id', 'project_id', 'event_type', 'object_kind',
                'mr_iid', 'pipeline_id', 'status', 'received_at', 'processed_at',
            ],
            returnableColumns: [
                'gitlab_event_id', 'connection_id', 'inbox_event_id', 'external_project_id',
                'project_id', 'event_type', 'object_kind', 'mr_iid', 'pipeline_id',
                'status', 'task_keys', 'received_at', 'processed_at', 'error',
                'replay_count', 'replayed_at', 'created_at',
            ],
            supportedActions: ['read', 'list'],
        );
    }

    /**
     * 注册 Archive 文档 provider；Archive 未安装（契约未绑定）时静默跳过。
     */
    private function registerDocProvider(): void
    {
        if (! $this->app->bound(DocProviderRegistryContract::class)) {
            return;
        }

        $this->app->make(DocProviderRegistryContract::class)->register(
            'gitlab',
            fn (int $tenantId, ?int $connectionId = null): DocProviderContract => new GitlabDocProvider(
                $this->app->make(GitlabConnectionService::class),
                $tenantId,
                $connectionId,
            ),
        );
    }

    /**
     * 注册 L1 只读 AI 工具（不返回原始 payload / 日志 / 凭据）。
     */
    private function registerTools(): void
    {
        if (! $this->app->bound(ToolRegistryContract::class)) {
            return;
        }

        $registry = $this->app->make(ToolRegistryContract::class);

        $registry->register(
            slug: 'gitlab_mr_summary',
            name: 'GitLab MR 摘要',
            description: '按 MR IID 汇总最近一次 GitLab 合并请求投影：状态、任务号、项目与分支信息（只读本地投影）。',
            handlerClass: GitlabMrSummaryHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'mr_iid' => ['type' => 'integer', 'description' => 'GitLab merge request IID'],
                    'external_project_id' => ['type' => 'string', 'description' => 'GitLab project ID（可选，缩小范围）'],
                ],
                'required' => ['mr_iid'],
            ],
            category: 'gitlab',
            risk: 'L1',
            audience: 'operator',
            dataResource: 'gitlab_webhook_events',
            requiredCapability: 'common.gitlab.project.view',
        );

        $registry->register(
            slug: 'gitlab_pipeline_failure_explain',
            name: 'GitLab Pipeline 失败解释',
            description: '查询 GitLab pipeline 投影并给出事实性解释；错误摘要脱敏并截断，P0 不读取完整 job 日志。',
            handlerClass: GitlabPipelineFailureExplainHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'pipeline_id' => ['type' => 'integer', 'description' => 'GitLab pipeline ID'],
                    'external_project_id' => ['type' => 'string', 'description' => 'GitLab project ID（可选）'],
                ],
                'required' => ['pipeline_id'],
            ],
            category: 'gitlab',
            risk: 'L1',
            audience: 'operator',
            dataResource: 'gitlab_webhook_events',
            requiredCapability: 'common.gitlab.pipeline.view',
        );

        $registry->register(
            slug: 'gitlab_event_query',
            name: 'GitLab 事件查询',
            description: '按事件类型/状态/项目查询 GitLab webhook 归一化投影；只返回事实字段，不含原始 payload 与请求头。',
            handlerClass: GitlabEventQueryHandler::class,
            schema: [
                'type' => 'object',
                'properties' => [
                    'event_type' => ['type' => 'string', 'description' => '如 gitlab.merge_request / gitlab.pipeline'],
                    'status' => ['type' => 'string', 'description' => '事件状态（open/merged/success/failed...）'],
                    'external_project_id' => ['type' => 'string', 'description' => 'GitLab project ID'],
                    'project_id' => ['type' => 'integer', 'description' => '宿主项目 ID'],
                    'limit' => ['type' => 'integer', 'description' => '返回条数上限（1-20）'],
                ],
                'required' => [],
            ],
            category: 'gitlab',
            risk: 'L1',
            audience: 'operator',
            dataResource: 'gitlab_webhook_events',
            requiredCapability: 'common.gitlab.event.view',
        );
    }

    private function registerScenes(): void
    {
        if (! $this->app->bound(SceneRegistryContract::class)) {
            return;
        }

        $this->app->make(SceneRegistryContract::class)
            ->registerScene('gitlab', 'GitLab 集成', 'Menu', 'GitLab 连接、项目投影与 Webhook 事件', 100);
    }
}
