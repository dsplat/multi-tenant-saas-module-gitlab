<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 创建/接管 GitLab 文档空间。
 *
 * - 提供 external_project_id：接管已存在的 GitLab 项目；
 * - 否则按 path 新建；同名已存在但无映射时由服务层返回 409。
 */
class StoreGitlabProjectRequest extends FormRequest
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
            'connection_id' => ['required', 'integer', 'min:1'],
            'project_id' => ['required', 'integer', 'min:1'],
            'archive_scope_id' => ['nullable', 'integer', 'min:1'],
            'external_project_id' => ['nullable', 'string', 'max:100'],
            'path' => ['required_without:external_project_id', 'nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['nullable', 'in:private,internal,public'],
            'default_branch' => ['nullable', 'string', 'max:255'],
            'initialize_with_readme' => ['nullable', 'boolean'],
        ];
    }
}
