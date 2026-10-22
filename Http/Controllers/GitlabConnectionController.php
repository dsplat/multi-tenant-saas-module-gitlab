<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Gitlab\Http\Requests\RotateGitlabTokenRequest;
use MultiTenantSaas\Modules\Gitlab\Http\Requests\StoreGitlabConnectionRequest;
use MultiTenantSaas\Modules\Gitlab\Http\Requests\UpdateGitlabConnectionRequest;
use MultiTenantSaas\Modules\Gitlab\Http\Resources\GitlabConnectionResource;
use MultiTenantSaas\Modules\Gitlab\Services\GitlabConnectionService;

/**
 * GitLab 连接管理（脱敏：永不返回 credentials 或片段）。
 */
class GitlabConnectionController extends BaseController
{
    public function __construct(private readonly GitlabConnectionService $connections) {}

    public function index(Request $request): JsonResponse
    {
        $items = array_map(
            static fn ($connection): array => (new GitlabConnectionResource($connection))->resolve(),
            $this->connections->list($this->tenantId()),
        );

        return $this->successResponse(['data' => $items, 'total' => count($items)]);
    }

    public function store(StoreGitlabConnectionRequest $request): JsonResponse
    {
        $connection = $this->connections->create($this->tenantId(), $request->validated());

        return $this->createdResponse(
            (new GitlabConnectionResource($connection))->resolve(),
            'GitLab 连接已创建',
        );
    }

    public function update(UpdateGitlabConnectionRequest $request, int $id): JsonResponse
    {
        $connection = $this->connections->update($this->tenantId(), $id, $request->validated());

        return $this->successResponse(
            (new GitlabConnectionResource($connection))->resolve(),
            'GitLab 连接已更新',
        );
    }

    public function test(Request $request, int $id): JsonResponse
    {
        $result = $this->connections->test($this->tenantId(), $id, $request->boolean('write_probe'));

        return $this->successResponse($result, $result['ok'] ? '连接可用' : '连接不可用');
    }

    public function rotate(RotateGitlabTokenRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();
        $connection = $this->connections->rotate(
            $this->tenantId(),
            $id,
            (string) $validated['token'],
            $validated['webhook_secret'] ?? null,
        );

        return $this->successResponse(
            (new GitlabConnectionResource($connection))->resolve(),
            'Token 已轮换',
        );
    }

    private function tenantId(): int
    {
        return (int) TenantContext::getId();
    }
}
