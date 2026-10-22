<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MultiTenantSaas\Modules\Integration\Models\IntegrationConnection;

/**
 * 连接脱敏输出。
 *
 * 明确不返回 credentials（token / webhook_secret），也不返回任何片段；
 * 只暴露凭据存在性、scope、过期时间与只读标记。
 *
 * @mixin IntegrationConnection
 */
class GitlabConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $credentials = (array) ($this->credentials ?? []);
        $config = (array) ($this->config ?? []);

        return [
            'connection_id' => (int) $this->connection_id,
            'name' => (string) $this->name,
            'provider' => (string) $this->provider,
            'base_url' => (string) $this->base_url,
            'status' => (string) $this->status,
            'has_token' => trim((string) ($credentials['token'] ?? '')) !== '',
            'webhook_configured' => trim((string) ($credentials['webhook_secret'] ?? '')) !== '',
            'token_scopes' => array_values((array) ($config['token_scopes'] ?? [])),
            'token_expires_at' => $config['token_expires_at'] ?? null,
            'read_only' => (bool) ($config['read_only'] ?? config('gitlab.default_read_only', true)),
            'doc_project_id' => $config['doc_project_id'] ?? null,
            'default_branch' => $config['default_branch'] ?? config('gitlab.docs.default_branch', 'main'),
            'last_checked_at' => $this->last_checked_at,
            'last_check_error' => $this->last_check_error,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
