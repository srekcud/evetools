import { createApp, nextTick, reactive, type App, type Ref } from 'vue'
import type { LocationQuery } from 'vue-router'
import { useTabQuery } from '@/composables/useTabQuery'

type IndustryTab = 'dashboard' | 'margins' | 'batch' | 'pivot'
type ProjectTab = 'steps' | 'shopping' | 'invention' | 'costs'

const INDUSTRY_TABS: IndustryTab[] = ['dashboard', 'margins', 'batch', 'pivot']
const DEFAULT_TAB: IndustryTab = 'dashboard'
const TRITANIUM_TYPE_ID = '34'
const PYERITE_TYPE_ID = '35'
const MEXALLON_TYPE_ID = '36'
const ISOGEN_TYPE_ID = '37'
const NOCXIUM_TYPE_ID = '38'

const PROJECT_TABS: ProjectTab[] = ['steps', 'shopping', 'invention', 'costs']
const DEFAULT_PROJECT_TAB: ProjectTab = 'steps'
const PROJECT_TAB_QUERY_KEY = 'projectTab'

const route = reactive<{ query: LocationQuery }>({ query: {} })
const routerReplace = vi.fn()
const mountedApps: App[] = []

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: routerReplace })) as never,
}))

function mountTabQuery(query: LocationQuery): Ref<IndustryTab> {
  route.query = query
  let activeTab!: Ref<IndustryTab>
  const app = createApp({
    setup: () => {
      activeTab = useTabQuery(INDUSTRY_TABS, DEFAULT_TAB)
      return () => null
    },
  })
  app.mount(document.createElement('div'))
  mountedApps.push(app)
  return activeTab
}

function mountProjectTabQuery(query: LocationQuery): Ref<ProjectTab> {
  route.query = query
  let activeTab!: Ref<ProjectTab>
  const app = createApp({
    setup: () => {
      activeTab = useTabQuery(PROJECT_TABS, DEFAULT_PROJECT_TAB, PROJECT_TAB_QUERY_KEY)
      return () => null
    },
  })
  app.mount(document.createElement('div'))
  mountedApps.push(app)
  return activeTab
}

beforeEach(() => {
  routerReplace.mockClear()
})

afterEach(() => {
  mountedApps.splice(0).forEach(app => app.unmount())
})

describe('useTabQuery', () => {
  it('activates the tab read from the query on mount', () => {
    const activeTab = mountTabQuery({ tab: 'batch' })

    expect(activeTab.value).toBe('batch')
  })

  it('falls back to the default tab when mounted with a tab that is not valid', () => {
    const activeTab = mountTabQuery({ tab: 'blueprints' })

    expect(activeTab.value).toBe(DEFAULT_TAB)
  })

  it('writes the chosen tab in the query and keeps the other query parameters', async () => {
    const activeTab = mountTabQuery({ typeId: TRITANIUM_TYPE_ID })

    activeTab.value = 'margins'
    await nextTick()

    expect(routerReplace).toHaveBeenCalledTimes(1)
    expect(routerReplace).toHaveBeenCalledWith({ query: { typeId: TRITANIUM_TYPE_ID, tab: 'margins' } })
  })

  it('removes the tab parameter when the default tab is chosen', async () => {
    const activeTab = mountTabQuery({ tab: 'pivot', typeId: PYERITE_TYPE_ID })

    activeTab.value = DEFAULT_TAB
    await nextTick()

    expect(routerReplace).toHaveBeenCalledTimes(1)
    expect(routerReplace).toHaveBeenCalledWith({ query: { typeId: PYERITE_TYPE_ID } })
  })

  it('follows an external change of the query tab', async () => {
    const activeTab = mountTabQuery({ tab: 'margins', typeId: MEXALLON_TYPE_ID })

    route.query = { tab: 'pivot', typeId: MEXALLON_TYPE_ID }
    await nextTick()

    expect(activeTab.value).toBe('pivot')
  })

  it('falls back to the default tab when the query tab becomes invalid', async () => {
    const activeTab = mountTabQuery({ tab: 'batch' })
    expect(activeTab.value).toBe('batch')

    route.query = { tab: 'shopping' }
    await nextTick()

    expect(activeTab.value).toBe(DEFAULT_TAB)
  })
})

describe('useTabQuery with a projectTab query key', () => {
  it('activates the project tab read from the projectTab query on mount', () => {
    const activeTab = mountProjectTabQuery({ tab: 'projects', projectTab: 'costs' })

    expect(activeTab.value).toBe('costs')
  })

  it('falls back to the default project tab when mounted with a projectTab that is not valid', () => {
    const activeTab = mountProjectTabQuery({ tab: 'projects', projectTab: 'margins' })

    expect(activeTab.value).toBe(DEFAULT_PROJECT_TAB)
  })

  it('ignores the tab query parameter on mount', () => {
    const activeTab = mountProjectTabQuery({ tab: 'shopping' })

    expect(activeTab.value).toBe(DEFAULT_PROJECT_TAB)
  })

  it('writes the chosen project tab in projectTab and keeps tab and the other query parameters', async () => {
    const activeTab = mountProjectTabQuery({ tab: 'projects', typeId: ISOGEN_TYPE_ID })

    activeTab.value = 'shopping'
    await nextTick()

    expect(routerReplace).toHaveBeenCalledTimes(1)
    expect(routerReplace).toHaveBeenCalledWith({
      query: { tab: 'projects', typeId: ISOGEN_TYPE_ID, projectTab: 'shopping' },
    })
  })

  it('removes the projectTab parameter and keeps tab when the default project tab is chosen', async () => {
    const activeTab = mountProjectTabQuery({ tab: 'projects', projectTab: 'invention', typeId: NOCXIUM_TYPE_ID })

    activeTab.value = DEFAULT_PROJECT_TAB
    await nextTick()

    expect(routerReplace).toHaveBeenCalledTimes(1)
    expect(routerReplace).toHaveBeenCalledWith({ query: { tab: 'projects', typeId: NOCXIUM_TYPE_ID } })
  })

  it('follows an external change of the projectTab query', async () => {
    const activeTab = mountProjectTabQuery({ tab: 'projects', projectTab: 'shopping' })

    route.query = { tab: 'projects', projectTab: 'costs' }
    await nextTick()

    expect(activeTab.value).toBe('costs')
  })

  it('keeps the project tab when only the tab query parameter changes', async () => {
    const activeTab = mountProjectTabQuery({ tab: 'projects', projectTab: 'invention' })

    route.query = { tab: 'margins', projectTab: 'invention' }
    await nextTick()

    expect(activeTab.value).toBe('invention')
    expect(routerReplace).not.toHaveBeenCalled()
  })
})

describe('useTabQuery without a query key', () => {
  it('ignores the projectTab query parameter', () => {
    const activeTab = mountTabQuery({ projectTab: 'batch' })

    expect(activeTab.value).toBe(DEFAULT_TAB)
  })
})
