import { http, extractListResult } from '@multi-tenant-saas/console/shared/http'

// ========== 类型定义 ==========

export type GitlabConnectionStatus = 'active' | 'disabled'

/** 连接脱敏视图：后端永不返回 token / webhook_secret / 片段 */
export interface GitlabConnection {
  connection_id: number
  name: string
  provider: string
  base_url: string
  status: GitlabConnectionStatus
  has_token: boolean
  webhook_configured: boolean
  token_scopes: string[]
  token_expires_at: string | null
  read_only: boolean
  doc_project_id: string | null
  default_branch: string | null
  last_checked_at: string | null
  last_check_error: string | null
  created_at: string
  updated_at: string
}

export interface GitlabConnectionPayload {
  name: string
  base_url: string
  token?: string
  webhook_secret?: string
  status?: GitlabConnectionStatus
  read_only?: boolean
  tls_verify?: boolean
  timeout?: number
  token_scopes?: string[]
  token_expires_at?: string | null
  doc_group?: string | null
  doc_project_id?: string | null
  default_branch?: string | null
  write_probe_project_id?: string | null
}

export interface GitlabConnectionTestResult {
  ok: boolean
  error: string | null
  checked_at: string
  read_only: boolean
}

export interface GitlabProject {
  gitlab_project_id: number
  connection_id: number
  project_id: number | null
  external_project_id: string
  path_with_namespace: string
  name: string | null
  default_branch: string | null
  visibility: string | null
  last_synced_at: string | null
  fetched_at: string | null
  metadata: Record<string, unknown>
  created_at: string
  updated_at: string
}

export interface GitlabProjectPayload {
  connection_id: number
  project_id: number
  archive_scope_id?: number
  external_project_id?: string
  path?: string
  name?: string
  description?: string
  visibility?: 'private' | 'internal' | 'public'
  default_branch?: string
  initialize_with_readme?: boolean
}

export interface GitlabProjectSaveResult {
  project: GitlabProject
  created: boolean
  adopted: boolean
  mapping_error: boolean
}

export interface GitlabEvent {
  gitlab_event_id: number
  connection_id: number
  inbox_event_id: number
  external_project_id: string | null
  project_id: number | null
  event_type: string
  object_kind: string | null
  mr_iid: number | null
  pipeline_id: number | null
  status: string | null
  task_keys: string[]
  received_at: string
  processed_at: string | null
  fetched_at: string | null
  error: string | null
  replay_count: number
  replayed_at: string | null
  created_at: string
}

export interface GitlabListParams {
  page?: number
  per_page?: number
  project_id?: number
  external_project_id?: string
  connection_id?: number
  event_type?: string
  status?: string
  keyword?: string
  mr_iid?: number
  pipeline_id?: number
}

export interface GitlabListResult<T> {
  data: T[]
  total: number
}

// ========== 连接 ==========

const BASE = '/gitlab'

export async function getGitlabConnections(): Promise<GitlabListResult<GitlabConnection>> {
  const res = await http.get<GitlabConnection[] | { data: GitlabConnection[]; total: number }>(`${BASE}/connections`)
  return extractListResult(res)
}

export async function createGitlabConnection(payload: GitlabConnectionPayload): Promise<GitlabConnection> {
  const res = await http.post<GitlabConnection>(`${BASE}/connections`, payload)
  return res.data
}

export async function updateGitlabConnection(
  connectionId: number,
  payload: Partial<GitlabConnectionPayload>,
): Promise<GitlabConnection> {
  const res = await http.put<GitlabConnection>(`${BASE}/connections/${connectionId}`, payload)
  return res.data
}

export async function testGitlabConnection(
  connectionId: number,
  writeProbe = false,
): Promise<GitlabConnectionTestResult> {
  const res = await http.post<GitlabConnectionTestResult>(`${BASE}/connections/${connectionId}/test`, {
    write_probe: writeProbe,
  })
  return res.data
}

export async function rotateGitlabToken(
  connectionId: number,
  token: string,
  webhookSecret?: string,
): Promise<GitlabConnection> {
  const res = await http.post<GitlabConnection>(`${BASE}/connections/${connectionId}/rotate`, {
    token,
    webhook_secret: webhookSecret || undefined,
  })
  return res.data
}

// ========== 项目投影 ==========

export async function getGitlabProjects(
  params: GitlabListParams = {},
): Promise<GitlabListResult<GitlabProject>> {
  const res = await http.get<GitlabProject[] | { data: GitlabProject[]; total: number }>(
    `${BASE}/projects`,
    { params },
  )
  return extractListResult(res)
}

export async function createGitlabProject(payload: GitlabProjectPayload): Promise<GitlabProjectSaveResult> {
  const res = await http.post<GitlabProjectSaveResult>(`${BASE}/projects`, payload)
  return res.data
}

// ========== 事件 / 重放 ==========

export async function getGitlabEvents(
  params: GitlabListParams = {},
): Promise<GitlabListResult<GitlabEvent>> {
  const res = await http.get<GitlabEvent[] | { data: GitlabEvent[]; total: number }>(
    `${BASE}/events`,
    { params },
  )
  return extractListResult(res)
}

export async function replayGitlabEvent(eventId: number): Promise<GitlabEvent> {
  const res = await http.post<GitlabEvent>(`${BASE}/events/${eventId}/replay`)
  return res.data
}

// ========== Pipeline 投影 ==========

export async function getGitlabPipelines(
  params: GitlabListParams = {},
): Promise<GitlabListResult<GitlabEvent>> {
  const res = await http.get<GitlabEvent[] | { data: GitlabEvent[]; total: number }>(
    `${BASE}/pipelines`,
    { params },
  )
  return extractListResult(res)
}

export async function getGitlabPipeline(pipelineId: number): Promise<GitlabEvent> {
  const res = await http.get<GitlabEvent>(`${BASE}/pipelines/${pipelineId}`)
  return res.data
}
