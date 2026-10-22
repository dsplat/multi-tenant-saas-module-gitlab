<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新 GitLab 连接（partial update）。
 */
class UpdateGitlabConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'base_url' => ['sometimes', 'string', 'max:500'],
            'token' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:active,disabled'],
            'read_only' => ['sometimes', 'boolean'],
            'tls_verify' => ['sometimes', 'boolean'],
            'timeout' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'token_scopes' => ['sometimes', 'array'],
            'token_scopes.*' => ['string', 'max:64'],
            'token_expires_at' => ['sometimes', 'nullable', 'date'],
            'doc_group' => ['sometimes', 'nullable', 'string', 'max:255'],
            'doc_project_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'default_branch' => ['sometimes', 'nullable', 'string', 'max:255'],
            'write_probe_project_id' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
