<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Support;

/**
 * 外部文本脱敏：用于 pipeline/MR 摘要与错误摘要的出口裁剪。
 *
 * 只做“明显凭据样式”的替换，不尝试理解业务字段；宁可多删不可漏掉
 * GitLab token（glpat-*）、Private-Token 头、Authorization 头与 URL 中的
 * access_token/token 查询参数。
 */
final class SensitiveText
{
    private const PATTERNS = [
        '/\bglpat-[A-Za-z0-9_\-]{4,}\b/' => '[REDACTED_TOKEN]',
        '/\bglrt-[A-Za-z0-9_\-]{4,}\b/' => '[REDACTED_TOKEN]',
        '/\bglcbt-[A-Za-z0-9_\-]{4,}\b/' => '[REDACTED_TOKEN]',
        '/\b(PRIVATE-TOKEN|Authorization)\s*:\s*\S+/i' => '$1: [REDACTED]',
        '/\bBearer\s+[A-Za-z0-9._\-]+/i' => 'Bearer [REDACTED]',
        '/([?&](?:access_)?token=)[^&\s]+/i' => '$1[REDACTED]',
        '/\b((?:access_)?token=)[^&\s]+/i' => '$1[REDACTED]',
    ];

    public static function redact(string $text): string
    {
        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function redactArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = self::redact($value);
            } elseif (is_array($value)) {
                $values[$key] = self::redactArray($value);
            }
        }

        return $values;
    }
}
