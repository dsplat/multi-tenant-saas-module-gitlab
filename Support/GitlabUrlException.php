<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Support;

use InvalidArgumentException;

/**
 * 连接 base_url 被 SSRF 守卫拒绝。
 */
class GitlabUrlException extends InvalidArgumentException {}
