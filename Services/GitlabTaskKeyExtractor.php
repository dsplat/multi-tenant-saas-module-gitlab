<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Services;

/**
 * 对账桥任务号提取。
 *
 * 来源由调用方拼接（分支/MR 标题/描述/commit message）；规则可配置，
 * 结果去重、大小写归一（统一大写）、按配置上限截断。
 */
class GitlabTaskKeyExtractor
{
    /**
     * @param  iterable<int, string|null>  $sources
     * @return list<string>
     */
    public function extract(iterable $sources): array
    {
        $pattern = (string) config('gitlab.task_keys.pattern', '[A-Z][A-Z0-9]+-\d+');
        $limit = max(1, (int) config('gitlab.task_keys.limit', 10));
        $keys = [];

        foreach ($sources as $source) {
            if (! is_string($source) || trim($source) === '') {
                continue;
            }

            $matched = [];

            if (@preg_match_all('~' . $pattern . '~iu', $source, $matched) === false) {
                continue;
            }

            foreach ($matched[0] ?? [] as $key) {
                $normalized = strtoupper((string) $key);

                if (! in_array($normalized, $keys, true)) {
                    $keys[] = $normalized;
                }

                if (count($keys) >= $limit) {
                    return $keys;
                }
            }
        }

        return $keys;
    }
}
