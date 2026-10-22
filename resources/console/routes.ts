import type { RouteRecordRaw } from 'vue-router'
import { view } from '@multi-tenant-saas/console/module-loader'

const routes: RouteRecordRaw[] = [
  // GitLab 集成
  {
    path: 'gitlab',
    name: 'GitlabManagement',
    component: view('Gitlab', 'GitlabManagement'),
    meta: { title: 'GitLab 集成', permission: 'gitlab.view' },
  },
]

export default routes
