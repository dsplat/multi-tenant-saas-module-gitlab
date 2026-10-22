<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Gitlab\Events;

/**
 * Merge Request merged 事件（对账桥按任务号消费）。
 */
final readonly class GitlabMergeRequestMerged extends GitlabMergeRequestEvent {}
