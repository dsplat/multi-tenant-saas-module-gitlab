<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 轮换 token：先探测新 token 可用，再替换凭据。
 */
class RotateGitlabTokenRequest extends FormRequest
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
            'token' => ['required', 'string', 'max:1000'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
        ];
    }
}
