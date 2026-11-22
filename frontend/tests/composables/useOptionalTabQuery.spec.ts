import { createApp, nextTick, reactive, type App, type Ref } from 'vue'
import type { LocationQuery, NavigationGuard } from 'vue-router'
import { useOptionalTabQuery } from '@/composables/useOptionalTabQuery'

type SectionKey = 'bom' | 'sales' | 'members'

const SECTION_KEYS: SectionKey[] = ['bom', 'sales', 'members']

const route = reactive<{ query: LocationQuery }>({ query: {} })
const routerReplace = vi.fn()
const routeUpdateGuards: NavigationGuard[] = []
const mountedApps: App[] = []

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: routerReplace })) as never,
  onBeforeRouteUpdate: ((guard: NavigationGuard) => { routeUpdateGuards.push(guard) }) as never,
}))

function mountOptionalTabQuery(query: LocationQuery): Ref<SectionKey | null> {
  route.query = query
  let openSection!: Ref<SectionKey | null>
  const app = createApp({
    setup: () => {
      openSection = useOptionalTabQuery(SECTION_KEYS)
      return () => null
    },
  })
  app.mount(document.createElement('div'))
  mountedApps.push(app)
  return openSection
}

function routeUpdateTo(query: LocationQuery): void {
  routeUpdateGuards.forEach(guard => guard({ query } as never, {} as never, vi.fn()))
}

beforeEach(() => {
  route.query = {}
  routerReplace.mockReset()
  routeUpdateGuards.length = 0
})

afterEach(() => {
  mountedApps.splice(0).forEach(app => app.unmount())
})

describe('useOptionalTabQuery', () => {
  it('opens no section when the query has no tab', () => {
    expect(mountOptionalTabQuery({}).value).toBeNull()
  })

  it('opens the section named by a valid tab', () => {
    expect(mountOptionalTabQuery({ tab: 'sales' }).value).toBe('sales')
  })

  it('opens no section for an unknown tab', () => {
    expect(mountOptionalTabQuery({ tab: 'phases' }).value).toBeNull()
  })

  it('puts the tab in the query when a section is opened and keeps the other keys', async () => {
    const openSection = mountOptionalTabQuery({ contribute: 'line-1' })

    openSection.value = 'members'
    await nextTick()

    expect(routerReplace).toHaveBeenCalledWith({ query: { contribute: 'line-1', tab: 'members' } })
  })

  it('removes the tab from the query when the section is closed', async () => {
    const openSection = mountOptionalTabQuery({ tab: 'bom', contribute: 'line-1' })

    openSection.value = null
    await nextTick()

    expect(routerReplace).toHaveBeenCalledWith({ query: { contribute: 'line-1' } })
  })

  it('follows a navigation to the same screen with another tab without rewriting the query', async () => {
    const openSection = mountOptionalTabQuery({ tab: 'bom' })

    routeUpdateTo({ tab: 'sales' })
    await nextTick()

    expect(openSection.value).toBe('sales')
    expect(routerReplace).not.toHaveBeenCalled()
  })

  it('does not follow the route query after the mount unless a route update announces it', async () => {
    const openSection = mountOptionalTabQuery({})

    route.query = { tab: 'planning' }
    await nextTick()

    expect(openSection.value).toBeNull()
  })
})
