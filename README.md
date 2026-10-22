# Gitlab 集成（GitLab 连接器）

GitLab 连接、项目映射、webhook 事件投影与文档仓 provider。连接与凭据统一存放在 Integration 的 `integration_connections`，模块不建 token 表。

## 分层与依赖

- 架构层：`common`（`config/module-architecture.php`）
- 依赖模块：`integration`
- 冲突声明：无
- 迁移：`2025_01_03_000018_gitlab_module.php`（2 张投影表）、`2025_01_04_000018_gitlab_module_data.php`（RBAC 权限，幂等）

## 配置 `config/gitlab.php`

| 键 | 说明 |
|---|---|
| `enabled` / `route_prefix` / `per_page` | 模块开关、路由前缀、分页 |
| `api_version` / `timeout` | GitLab API 版本与超时 |
| `tls_verify` | 默认开启，禁止关闭后用于生产 |
| `allow_insecure_http` / `allow_private_hosts` / `allowed_hosts` | SSRF 防护开关；默认只允许 HTTPS 公网主机，`allowed_hosts` 为空表示不额外放行 |
| `http.retry_times` / `retry_sleep_ms` / `max_redirects` | 重试与重定向策略（默认不跟随重定向） |
| `webhook.max_body_bytes` / `allowed_events` | webhook 体积上限与事件白名单 |
| `task_keys.pattern` / `limit` | 任务号提取规则与单事件上限（默认 10） |
| `docs.max_file_size` / `max_path_length` / `default_branch` / `probe_project_id` | 文档仓读写约束与健康探针项目 |
| `default_read_only` | 新建连接默认只读（fail-closed）；写文档/建项目需显式 `read_only=false` |

## 路由

租户管理面（`api/v1/gitlab`，`operator.identify` + capability）：

- `connections`：列表、创建、更新、测试、轮换
- `projects`：列表、创建/接管文档空间
- `events`：列表、重放
- `pipelines`：列表、详情

公开面（无登录）：

- `POST api/v1/gitlab/webhook/{connection}`：`X-Gitlab-Token` 常量时间校验 + 事件类型白名单 + body 上限 + throttle

## 能力点

默认权限集：`gitlab.view` / `gitlab.manage`。

- `common.gitlab.connection.view` / `connection.manage`
- `common.gitlab.project.view` / `project.manage`
- `common.gitlab.event.view` / `event.replay`
- `common.gitlab.pipeline.view`
- `common.gitlab.doc.write`（受连接 `read_only` 限制）
- 兼容别名：`list` / `read` → 查看；`create` / `update` / `delete` → 管理

## 事件处理

- webhook 先写 `integration_inbox_events`（按 `event_uuid` / payload hash 幂等），再入队 `ProcessGitlabWebhookJob`。
- 任务携带 `TaskAuthEnvelope::forChannelService('gitlab.webhook', ...)`，服务名在 Provider 中 `trustService` 注册。
- 归一化后写 `gitlab_webhook_events` 并更新 `gitlab_projects` 投影（MR、pipeline、tag、push）。
- 失败事件保留错误信息，可通过管理接口从 inbox 重放；重复投递不重复派发。

## 安全

- 凭据只写 `integration_connections.credentials`（`encrypted:array`），任何响应只回脱敏字段。
- 禁止 root / 个人 PAT 作为系统集成凭据；推荐最小权限 project/group token。
- `GitlabUrlGuard` 拒绝非 HTTPS、内网/保留地址、路径穿越与带凭据 URL；`SensitiveText` 在异常与日志中脱敏 `glpat-` / `glrt-` / `glcbt-` 与 `token=` 参数。
- webhook 只接受白名单事件，超限直接拒绝。

## 文档 provider

向 Archive 的 `DocProviderRegistry` 注册 `gitlab` provider：读写 UTF-8 文本、二进制流、移动、删除、`createDirectory`（`.gitkeep`）与健康检查。文档仓由连接上的 `doc_project_id` 指定，未配置时返回 `unsupported`。

## 数据表

`gitlab_projects`、`gitlab_webhook_events`。连接、inbox、游标与同步运行记录归 Integration，不在本模块重复建表。

## 命令

`gitlab:sync-projects` 同步/刷新项目映射与投影，供定时任务调度。

## 测试

```bash
php artisan test --parallel --processes=2 tests/Gitlab
```

真实 GitLab 验证为可选门：`RUN_EXTERNAL_TESTS=1 EXTERNAL_TEST_TARGET=gitlab`，测试资源必须使用 `pmtest-` 前缀，禁止触碰生产项目。
