<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 新建 GitLab 连接。token 只进 credentials（encrypted:array），不回显。
 */
class StoreGitlabConnectionRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'base_url' => ['required', 'string', 'max:500'],
            'token' => ['required', 'string', 'max:1000'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,disabled'],
            'read_only' => ['nullable', 'boolean'],
            'tls_verify' => ['nullable', 'boolean'],
            'timeout' => ['nullable', 'integer', 'min:1', 'max:60'],
            'token_scopes' => ['nullable', 'array'],
            'token_scopes.*' => ['string', 'max:64'],
            'token_expires_at' => ['nullable', 'date'],
            'doc_group' => ['nullable', 'string', 'max:255'],
            'doc_project_id' => ['nullable', 'string', 'max:100'],
            'default_branch' => ['nullable', 'string', 'max:255'],
            'write_probe_project_id' => ['nullable', 'string', 'max:100'],
        ];
    }
}
