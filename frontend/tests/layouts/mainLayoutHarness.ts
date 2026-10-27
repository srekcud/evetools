import { flushPromises, mount } from '@vue/test-utils'
import type { Window as HappyDomWindow } from 'happy-dom'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import MainLayout from '@/layouts/MainLayout.vue'

export const WIDE_VIEWPORT_WIDTH = 1440
export const PAGE_CONTENT_SELECTOR = '[data-testid="page-content"]'

const navigationRoutes = [
  '/dashboard',
  '/ledger',
  '/industry',
  '/group-industry',
  '/appraisal',
  '/planetary',
  '/market',
  '/assets',
  '/characters',
]

interface MainLayoutMountOptions {
  viewportWidth?: number
  storedCollapsedGroups?: string
}

export function setViewportWidth(width: number): void {
  (window as unknown as HappyDomWindow).happyDOM.setViewport({ width })
}

export async function mountMainLayoutAt(
  path: string,
  { viewportWidth = WIDE_VIEWPORT_WIDTH, storedCollapsedGroups }: MainLayoutMountOptions = {},
) {
  localStorage.clear()
  if (storedCollapsedGroups !== undefined) {
    localStorage.setItem('evetools_nav_collapsed', storedCollapsedGroups)
  }
  setViewportWidth(viewportWidth)
  const pinia = createPinia()
  setActivePinia(pinia)
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ hasAccess: false, characterName: null }), { status: 200 })))
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
