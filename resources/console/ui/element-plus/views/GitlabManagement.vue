<template>
  <div class="gitlab-page">
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>GitLab 连接器</span>
          <el-button type="primary" @click="openConnectionDialog()">新建连接</el-button>
        </div>
      </template>

      <el-tabs v-model="activeTab" @tab-change="handleTabChange">
        <el-tab-pane label="连接" name="connections">
          <el-table v-loading="connectionsLoading" :data="connections" border stripe>
            <el-table-column prop="name" label="名称" min-width="140" show-overflow-tooltip />
            <el-table-column prop="base_url" label="地址" min-width="220" show-overflow-tooltip />
            <el-table-column label="状态" width="100">
              <template #default="{ row }">
                <el-tag :type="row.status === 'active' ? 'success' : 'info'">
                  {{ row.status === 'active' ? '启用' : '停用' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="只读" width="80">
              <template #default="{ row }">
                <el-tag :type="row.read_only ? 'warning' : 'success'">{{ row.read_only ? '是' : '否' }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="Token" width="100">
              <template #default="{ row }">
                <el-tag :type="row.has_token ? 'success' : 'danger'">{{ row.has_token ? '已配置' : '缺失' }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="最近检查" min-width="170">
              <template #default="{ row }">
                <span v-if="row.last_check_error" class="error-text">{{ row.last_check_error }}</span>
                <span v-else>{{ row.last_checked_at || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="200" fixed="right">
              <template #default="{ row }">
                <el-button link type="primary" :loading="testingId === row.connection_id" @click="test(row)">
                  测试
                </el-button>
                <el-button link type="primary" @click="openConnectionDialog(row)">编辑</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="项目投影" name="projects">
          <div class="filter-bar">
            <el-input v-model="projectKeyword" placeholder="路径/名称关键词" clearable style="width: 220px" @keyup.enter="loadProjects" />
            <el-button type="primary" @click="loadProjects">查询</el-button>
          </div>
          <el-table v-loading="projectsLoading" :data="projects" border stripe>
            <el-table-column prop="path_with_namespace" label="GitLab 项目" min-width="220" show-overflow-tooltip />
            <el-table-column prop="external_project_id" label="Project ID" width="120" />
            <el-table-column prop="project_id" label="宿主项目" width="120" />
            <el-table-column prop="default_branch" label="默认分支" width="120" />
            <el-table-column prop="last_synced_at" label="最近同步" min-width="170" />
          </el-table>
          <el-pagination
            class="pager"
            layout="total, prev, pager, next"
            :total="projectTotal"
            :current-page="projectPage"
            :page-size="perPage"
            @current-change="handleProjectPage"
          />
        </el-tab-pane>

        <el-tab-pane label="Webhook 事件" name="events">
          <div class="filter-bar">
            <el-select v-model="eventType" placeholder="事件类型" clearable style="width: 200px">
              <el-option label="Merge Request" value="gitlab.merge_request" />
              <el-option label="Pipeline" value="gitlab.pipeline" />
              <el-option label="Tag Push" value="gitlab.tag_push" />
              <el-option label="Push" value="gitlab.push" />
            </el-select>
            <el-select v-model="eventStatus" placeholder="状态" clearable style="width: 140px">
              <el-option label="opened" value="opened" />
              <el-option label="merged" value="merged" />
              <el-option label="success" value="success" />
              <el-option label="failed" value="failed" />
            </el-select>
            <el-button type="primary" @click="loadEvents">查询</el-button>
          </div>
          <el-table v-loading="eventsLoading" :data="events" border stripe>
            <el-table-column prop="event_type" label="类型" width="190" />
            <el-table-column prop="status" label="状态" width="110" />
            <el-table-column prop="external_project_id" label="Project ID" width="120" />
            <el-table-column prop="mr_iid" label="MR" width="80" />
            <el-table-column prop="pipeline_id" label="Pipeline" width="100" />
            <el-table-column label="任务号" min-width="160">
              <template #default="{ row }">
                <el-tag v-for="key in row.task_keys" :key="key" size="small" class="task-key">{{ key }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="received_at" label="接收时间" min-width="170" />
            <el-table-column label="操作" width="110" fixed="right">
              <template #default="{ row }">
                <el-button v-if="row.error || !row.processed_at" link type="warning" @click="replay(row)">
                  重放
                </el-button>
                <span v-else>-</span>
              </template>
            </el-table-column>
          </el-table>
          <el-pagination
            class="pager"
            layout="total, prev, pager, next"
            :total="eventTotal"
            :current-page="eventPage"
            :page-size="perPage"
            @current-change="handleEventPage"
          />
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <el-dialog
      v-model="connectionDialogVisible"
      :title="editingConnectionId ? '编辑连接' : '新建连接'"
      width="560px"
      :close-on-click-modal="false"
    >
      <el-form ref="connectionFormRef" :model="connectionForm" :rules="connectionRules" label-width="110px">
        <el-form-item label="名称" prop="name">
          <el-input v-model="connectionForm.name" maxlength="100" />
        </el-form-item>
        <el-form-item label="GitLab 地址" prop="base_url">
          <el-input v-model="connectionForm.base_url" placeholder="https://gitlab.example.com" />
        </el-form-item>
        <el-form-item :label="editingConnectionId ? '新 Token' : 'Token'" :prop="editingConnectionId ? undefined : 'token'">
          <el-input v-model="connectionForm.token" type="password" show-password placeholder="glpat-..." />
        </el-form-item>
        <el-form-item label="Webhook Secret">
          <el-input v-model="connectionForm.webhook_secret" type="password" show-password />
        </el-form-item>
        <el-form-item label="文档项目 ID">
          <el-input v-model="connectionForm.doc_project_id" placeholder="Repository Files API 使用的 project ID" />
        </el-form-item>
        <el-form-item label="只读">
          <el-switch v-model="connectionForm.read_only" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="connectionDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="savingConnection" @click="saveConnection">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  createGitlabConnection,
  getGitlabConnections,
  getGitlabEvents,
  getGitlabProjects,
  replayGitlabEvent,
  testGitlabConnection,
  updateGitlabConnection,
} from '@modules/Gitlab/api/gitlab'
import type {
  GitlabConnection,
  GitlabConnectionPayload,
  GitlabEvent,
  GitlabProject,
} from '@modules/Gitlab/api/gitlab'

const activeTab = ref('connections')
const perPage = 20

const connections = ref<GitlabConnection[]>([])
const connectionsLoading = ref(false)
const testingId = ref<number | null>(null)

const projects = ref<GitlabProject[]>([])
const projectsLoading = ref(false)
const projectTotal = ref(0)
const projectPage = ref(1)
const projectKeyword = ref('')

const events = ref<GitlabEvent[]>([])
const eventsLoading = ref(false)
const eventTotal = ref(0)
const eventPage = ref(1)
const eventType = ref('')
const eventStatus = ref('')

const connectionDialogVisible = ref(false)
const editingConnectionId = ref<number | null>(null)
const savingConnection = ref(false)
const connectionFormRef = ref<FormInstance>()

const connectionForm = reactive<GitlabConnectionPayload>({
  name: '',
  base_url: '',
  token: '',
  webhook_secret: '',
  doc_project_id: '',
  read_only: true,
})

const connectionRules: FormRules = {
  name: [{ required: true, message: '请输入连接名称', trigger: 'blur' }],
  base_url: [{ required: true, message: '请输入 GitLab 地址', trigger: 'blur' }],
  token: [{ required: true, message: '请输入 Access Token', trigger: 'blur' }],
}

async function loadConnections(): Promise<void> {
  connectionsLoading.value = true
  try {
    const result = await getGitlabConnections()
    connections.value = result.data
  } catch {
    ElMessage.error('加载连接失败')
  } finally {
    connectionsLoading.value = false
  }
}

async function test(row: GitlabConnection): Promise<void> {
  testingId.value = row.connection_id
  try {
    const result = await testGitlabConnection(row.connection_id)
    if (result.ok) {
      ElMessage.success('连接可用')
    } else {
      ElMessage.error(result.error || '连接不可用')
    }
    await loadConnections()
  } catch {
    ElMessage.error('测试失败')
  } finally {
    testingId.value = null
  }
}

function openConnectionDialog(row?: GitlabConnection): void {
  editingConnectionId.value = row?.connection_id ?? null
  connectionForm.name = row?.name ?? ''
  connectionForm.base_url = row?.base_url ?? ''
  connectionForm.token = ''
  connectionForm.webhook_secret = ''
  connectionForm.doc_project_id = row?.doc_project_id ?? ''
  connectionForm.read_only = row?.read_only ?? true
  connectionDialogVisible.value = true
}

async function saveConnection(): Promise<void> {
  const valid = await connectionFormRef.value?.validate().catch(() => false)
  if (!valid) return

  savingConnection.value = true
  try {
    if (editingConnectionId.value) {
      const payload = { ...connectionForm }
      if (!payload.token) delete payload.token
      if (!payload.webhook_secret) delete payload.webhook_secret
      await updateGitlabConnection(editingConnectionId.value, payload)
      ElMessage.success('连接已更新')
    } else {
      await createGitlabConnection({ ...connectionForm })
      ElMessage.success('连接已创建')
    }
    connectionDialogVisible.value = false
    await loadConnections()
  } catch {
    ElMessage.error('保存失败')
  } finally {
    savingConnection.value = false
  }
}

async function loadProjects(): Promise<void> {
  projectsLoading.value = true
  try {
    const result = await getGitlabProjects({
      page: projectPage.value,
      per_page: perPage,
      keyword: projectKeyword.value || undefined,
    })
    projects.value = result.data
    projectTotal.value = result.total
  } catch {
    ElMessage.error('加载项目投影失败')
  } finally {
    projectsLoading.value = false
  }
}

function handleProjectPage(next: number): void {
  projectPage.value = next
  loadProjects()
}

async function loadEvents(): Promise<void> {
  eventsLoading.value = true
  try {
    const result = await getGitlabEvents({
      page: eventPage.value,
      per_page: perPage,
      event_type: eventType.value || undefined,
      status: eventStatus.value || undefined,
    })
    events.value = result.data
    eventTotal.value = result.total
  } catch {
    ElMessage.error('加载事件失败')
  } finally {
    eventsLoading.value = false
  }
}

function handleEventPage(next: number): void {
  eventPage.value = next
  loadEvents()
}

async function replay(row: GitlabEvent): Promise<void> {
  try {
    await replayGitlabEvent(row.gitlab_event_id)
    ElMessage.success('事件已重放')
    await loadEvents()
  } catch {
    ElMessage.error('重放失败（仅失败事件可重放）')
  }
}

function handleTabChange(name: string): void {
  if (name === 'projects' && projects.value.length === 0) loadProjects()
  if (name === 'events' && events.value.length === 0) loadEvents()
}

onMounted(loadConnections)
</script>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.filter-bar {
  display: flex;
  gap: 12px;
  margin-bottom: 16px;
}

.pager {
  margin-top: 16px;
  justify-content: flex-end;
}

.error-text {
  color: var(--el-color-danger);
}

.task-key {
  margin-right: 4px;
}
</style>
