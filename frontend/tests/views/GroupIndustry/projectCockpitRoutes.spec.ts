import { createPinia, setActivePinia } from 'pinia'
import router from '@/router'
import { useAuthStore } from '@/stores/auth'

const PROJECT_ID = 'project-nyx-batch'

beforeEach(async () => {
  setActivePinia(createPinia())
  const authStore = useAuthStore()
  authStore.token = 'pilot-token'
  authStore.user = { id: 7 } as never
  await router.push('/ledger')
})

describe('project cockpit routes', () => {
  it('serves the cockpit on the industry project route and redirects the former group industry detail to it, params and query kept', async () => {
    const resolved = router.resolve(`/industry/projects/${PROJECT_ID}`)

    await router.push(`/group-industry/${PROJECT_ID}?tab=sales`)
    const { name, params, query } = router.currentRoute.value

    expect({ resolvedName: resolved.name, resolvedId: resolved.params.id, name, params, query }).toEqual({
      resolvedName: 'industry-project',
      resolvedId: PROJECT_ID,
      name: 'industry-project',
      params: { id: PROJECT_ID },
      query: { tab: 'sales' },
    })
  })
})
