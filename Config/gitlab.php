<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | GitLab 连接器配置
    |--------------------------------------------------------------------------
    | 通过 config('gitlab.xxx') 读取。基类 mergeModuleConfig() 会以模块名
    | （gitlab）为键合并本文件。凭据（token / webhook secret）不在这里配置，
    | 统一存 integration_connections.credentials（encrypted:array）。
    */

    'enabled' => true,

    // 路由前缀（留空则挂在 api/v1 下）
    'route_prefix' => '',

    // 列表默认每页条数
    'per_page' => 20,

    'api_version' => 'v4',

    // 默认连接/请求超时（秒）
    'timeout' => 10,

    // TLS 校验默认开启；自签实例需在连接 config 显式关闭并记录风险
    'tls_verify' => true,

    /*
     * SSRF 防护。
     * - allow_insecure_http：默认只允许 https；
     * - allow_private_hosts：仅供本地/测试环境，生产必须保持 false；
     * - allowed_hosts：非空时作为主机白名单（仍会拒绝私网地址）。
     */
    'allow_insecure_http' => env('GITLAB_ALLOW_INSECURE_HTTP', false),
    'allow_private_hosts' => env('GITLAB_ALLOW_PRIVATE_HOSTS', false),
    'allowed_hosts' => [],

    // 429/5xx 退避重试；retry_times=1 表示最多重试一次（共两次请求）
    'http' => [
        'retry_times' => 1,
        'retry_sleep_ms' => 200,
        'max_redirects' => 0,
    ],

    'webhook' => [
        // 请求体上限（默认 2MB）
        'max_body_bytes' => 2 * 1024 * 1024,
        // 允许的 X-Gitlab-Event 头（P0 仅四类）
        'allowed_events' => [
            'Push Hook',
            'Tag Push Hook',
            'Merge Request Hook',
            'Pipeline Hook',
        ],
    ],

    // 对账桥任务号提取
    'task_keys' => [
        'pattern' => '[A-Z][A-Z0-9]+-\d+',
        'limit' => 10,
    ],

    'docs' => [
        // 单文件上限（字节）
        'max_file_size' => 25 * 1024 * 1024,
        'max_path_length' => 500,
        'default_branch' => 'main',
        // 空数组表示不限制 MIME（Archive 侧记录 MIME）
        'allowed_mime_types' => [],
        // 写探针专用项目 ID；仅在连接测试显式 probe=write 时使用
        'probe_project_id' => null,
    ],

    // 新建连接默认只读（fail-closed）；写文档需显式 read_only=false
    'default_read_only' => true,

    // 文档仓 project 创建参数
    'projects' => [
        'initialize_with_readme' => true,
        'visibility' => 'private',
        'per_page' => 100,
        'max_pages' => 20,
    ],
];
