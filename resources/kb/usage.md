GitLab 连接器
================

模块标识：gitlab
后台入口：Console → GitLab 集成（路由 `gitlab`）
能力面：连接管理、项目投影与映射、Webhook 事件、Pipeline 只读投影、Archive 文档仓 provider

## 定位与边界

- 连接/凭据统一落 Integration：`integration_connections`（provider=`gitlab`），token 只存在 `credentials`（encrypted:array）。
- 本模块只建两张投影表：`gitlab_projects`、`gitlab_webhook_events`；**不建** `gitlab_tokens` / `gitlab_connections` / `gitlab_project_mappings`。
- 原始 webhook payload 只进 `integration_inbox_events`，投影表不存 payload。
- 不直连云效、不持有云效 token；对账由宿主或 Yunxiao 监听事件完成。
- P0 只读投影：不浏览代码、不合并 MR、不触发流水线、不做门禁判定。

## 连接配置

| 项 | 说明 |
|---|---|
| `base_url` | GitLab 实例地址，默认仅 https，拒绝 localhost/私网/链路本地/元数据地址 |
| `credentials.token` | Access Token（`read_api` 读；写文档需 `api`） |
| `credentials.webhook_secret` | webhook 校验 secret，独立于 token |
| `read_only` | 默认 true；true 时文档写/建项目/写探针一律拒绝 |
| `doc_project_id` | Archive 文档仓对应的 GitLab project ID |
| `default_branch` | 文档仓默认分支（默认 `main`） |
| `token_scopes` / `token_expires_at` | 记录实际 scope 与过期时间，用于能力判断与提醒 |

API 只返回 `has_token` / `webhook_configured` / `token_scopes` / `token_expires_at` / `read_only` / `last_checked_at` / `last_check_error`，绝不回显 token 或片段。

## RBAC 能力点

| 能力点 | 权限 |
|---|---|
| `common.gitlab.connection.view` | `gitlab.view` |
| `common.gitlab.connection.manage` | `gitlab.manage` |
| `common.gitlab.project.view` | `gitlab.view` |
| `common.gitlab.project.manage` | `gitlab.manage` |
| `common.gitlab.event.view` | `gitlab.view` |
| `common.gitlab.event.replay` | `gitlab.manage` |
| `common.gitlab.pipeline.view` | `gitlab.view` |
| `common.gitlab.doc.write` | `gitlab.manage`（仍受连接 read_only 约束） |

旧脚手架能力点 `common.gitlab.list/read/create/update/delete` 保留为兼容别名。

## API

前缀 `api/v1`，租户路由经 `tenant.identify` + `operator.identify` + `capability`。

| 方法 | 路径 | 能力点 |
|---|---|---|
| GET | `gitlab/connections` | connection.view |
| POST | `gitlab/connections` | connection.manage |
| PUT | `gitlab/connections/{id}` | connection.manage |
| POST | `gitlab/connections/{id}/test` | connection.manage |
| POST | `gitlab/connections/{id}/rotate` | connection.manage |
| GET | `gitlab/projects` | project.view |
| POST | `gitlab/projects` | project.manage |
| GET | `gitlab/events` | event.view |
| POST | `gitlab/events/{id}/replay` | event.replay |
| GET | `gitlab/pipelines` | pipeline.view |
| GET | `gitlab/pipelines/{id}` | pipeline.view |
| POST | `gitlab/webhook/{connection}` | 公开，X-Gitlab-Token 验签 |

列表统一返回 `{data: {data: [...], total: n}}`。

## Webhook

- 地址：`POST /api/v1/gitlab/webhook/{connection}`，无登录。
- 校验：`X-Gitlab-Token` 与 `credentials.webhook_secret` 常量时间比较；无效统一 401，不解释原因；body 上限 2MB；`X-Gitlab-Event` 只接受 Push / Tag Push / Merge Request / Pipeline。
- 幂等：外部事件 ID 优先 `X-Gitlab-Event-UUID`，缺失用 payload SHA-256；Integration 收件箱唯一键去重；投影表 `inbox_event_id` 唯一。
- 处理：入队 `ProcessGitlabWebhookJob`（异步；测试/同步队列下内联执行）→ 归一化投影 → 派发 `GitlabWebhookReceived` + MR/Pipeline/Tag 领域事件。
- 乱序：MR 按 `updated_at`、pipeline 按自增 ID 判新旧，旧事件不覆盖最新状态、不派发领域事件。
- 失败：投影记录脱敏 `error`、收件箱置 failed、派发 `GitlabWebhookFailed`；管理员可对失败事件 `POST events/{id}/replay` 重放（记录次数/时间，成功后再重放返回 409）。

## 项目映射

- 外部键固定为 GitLab project ID（path 可改，不影响映射）。
- `external_mappings`：`gitlab/project` → `project`（宿主项目）、`gitlab/doc_project` → `archive_scope`（Archive 目录）。
- 幂等：同一外部 ID 只写一条映射；路径已存在但无映射 → 409，要求人工确认接管，不自动覆盖。
- 创建成功但映射写入失败 → 项目 metadata 记录孤儿 ID，重试补映射，不自动删除 GitLab 项目。

## Archive 文档 provider

`GitlabDocProvider` 实现 `DocProviderContract`，通过 Repository Files API：

- UTF-8 文本按 `text` 提交，二进制按 `base64`；
- 路径逐段 URL 编码，拒绝 `..`/反斜杠/控制字符/超长路径；
- commit message 固定携带 `source` / `actor` / `doc_id`；
- `createDirectory` 用 `.gitkeep` 占位；删除幂等；move = 新路径写入 + 旧路径删除；
- 错误归一化：404→NOT_FOUND、400/409/422→CONFLICT、401/403→PERMISSION_DENIED、429→RATE_LIMITED、5xx→UNAVAILABLE；
- 只读/停用连接写操作 → PERMISSION_DENIED；不 force push。

## 命令

```bash
php artisan gitlab:sync-projects --tenant=1 --dry-run
php artisan gitlab:sync-projects --tenant=1 --connection=123
```

dry-run 只统计本地已有投影，不调用 GitLab。

## AI 工具（L1 只读）

| slug | 用途 |
|---|---|
| `gitlab_mr_summary` | 按 MR IID 汇总投影状态/任务号/分支 |
| `gitlab_pipeline_failure_explain` | pipeline 事实解释；错误摘要脱敏、截断，不读完整日志 |
| `gitlab_event_query` | 按类型/状态/项目查询事件投影，不含原始 payload/请求头 |

## 安全与限制

- token 不写日志/异常/测试快照；异常只含 HTTP 状态。
- SSRF：默认 https、主机校验、不跟随重定向、可配置 `allowed_hosts` 白名单。
- P0 未实现：真实 GitLab opt-in 联调、异步队列真实 worker 验证、pipeline job 完整日志读取。
