import { flushPromises, mount } from '@vue/test-utils'
import type { Window as HappyDomWindow } from 'happy-dom'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { MODULE_LAST_PAGE_STORAGE_KEY } from '@/composables/useModuleLastPage'
import en from '@/i18n/locales/en.json'
import MainLayout from '@/layouts/MainLayout.vue'

export const WIDE_VIEWPORT_WIDTH = 1440
export const PAGE_CONTENT_SELECTOR = '[data-testid="page-content"]'

const navigationRoutes = [
  '/dashboard',
  '/ledger',
  '/industry',
  '/group-industry',
  '/group-industry/p1',
  '/industry/projects/p1',
  '/appraisal',
  '/planetary',
  '/mining/operations',
  '/mining/moons',
  '/market',
  '/assets',
  '/characters',
]

interface ApiAnswer {
  status: number
  body: unknown
}

interface MainLayoutMountOptions {
  viewportWidth?: number
  storedCollapsedGroups?: string
  storedHiddenModules?: string[]
  storedModuleLastPage?: string
  industryActionsAnswer?: ApiAnswer
}

export const INDUSTRY_ACTIONS_URL = '/api/industry/actions'

function stubbedApiAnswer(url: string): unknown {
  if (url === '/api/industry/projects' || url === INDUSTRY_ACTIONS_URL) {
    return []
  }
  if (url.startsWith('/api/industry/search?q=')) {
    return { results: [] }
  }
  return { hasAccess: false, characterName: null }
}

export function setViewportWidth(width: number): void {
  (window as unknown as HappyDomWindow).happyDOM.setViewport({ width })
}

export async function mountMainLayoutAt(
  path: string,
  { viewportWidth = WIDE_VIEWPORT_WIDTH, storedCollapsedGroups, storedHiddenModules, storedModuleLastPage, industryActionsAnswer }: MainLayoutMountOptions = {},
) {
  localStorage.clear()
  if (storedHiddenModules !== undefined) {
    localStorage.setItem('evetools_hidden_modules', JSON.stringify(storedHiddenModules))
  }
  if (storedCollapsedGroups !== undefined) {
    localStorage.setItem('evetools_nav_collapsed', storedCollapsedGroups)
  }
  if (storedModuleLastPage !== undefined) {
    localStorage.setItem(MODULE_LAST_PAGE_STORAGE_KEY, storedModuleLastPage)
  }
  setViewportWidth(viewportWidth)
  const pinia = createPinia()
  setActivePinia(pinia)
  vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
    const url = String(input)
    if (url === INDUSTRY_ACTIONS_URL && industryActionsAnswer !== undefined) {
      return new Response(JSON.stringify(industryActionsAnswer.body), { status: industryActionsAnswer.status })
    }
    return new Response(JSON.stringify(stubbedApiAnswer(url)), { status: 200 })
  }))
  const router = createRouter({
    history: createMemoryHistory(),
    routes: navigationRoutes.map(route => ({ path: route, component: { template: '<div />' } })),
  })
  await router.push(path)
  await router.isReady()
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(MainLayout, {
    attachTo: document.body,
    slots: { default: '<p data-testid="page-content">Page content</p>' },
    global: {
      plugins: [pinia, i18n, router],
      stubs: { NotificationBell: true, LegalFooter: true, LoadingSpinner: true },
    },
  })
  await flushPromises()
  return { wrapper, router }
}
