import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import type { DOMWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import MainLayout from '@/layouts/MainLayout.vue'

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

async function mountMainLayoutAt(path: string) {
  localStorage.clear()
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
    global: {
      plugins: [pinia, i18n, router],
      stubs: { NotificationBell: true, LegalFooter: true, LoadingSpinner: true },
    },
  })
  await flushPromises()
  return wrapper
}

function navigationLink(wrapper: Awaited<ReturnType<typeof mountMainLayoutAt>>, label: string): DOMWrapper<Element> | undefined {
  return wrapper.findAll('a[href]').find(link => link.text().trim() === label)
}

enableAutoUnmount(afterEach)

describe('MainLayout sidebar navigation', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('renders each simple navigation entry as a link to its route', async () => {
    const wrapper = await mountMainLayoutAt('/dashboard')

    expect(navigationLink(wrapper, 'Dashboard')?.attributes('href')).toBe('/dashboard')
    expect(navigationLink(wrapper, 'Revenue')?.attributes('href')).toBe('/ledger')
    expect(navigationLink(wrapper, 'Market')?.attributes('href')).toBe('/market')
    expect(navigationLink(wrapper, 'Inventory')?.attributes('href')).toBe('/assets')
  })

  it('renders each group child as a link to its route', async () => {
    const wrapper = await mountMainLayoutAt('/dashboard')

    expect(navigationLink(wrapper, 'Industry')?.attributes('href')).toBe('/industry')
    expect(navigationLink(wrapper, 'Group Industry')?.attributes('href')).toBe('/group-industry')
    expect(navigationLink(wrapper, 'Appraisal')?.attributes('href')).toBe('/appraisal')
    expect(navigationLink(wrapper, 'PI')?.attributes('href')).toBe('/planetary')
  })

  it('renders the characters entry as a link to the characters route', async () => {
    const wrapper = await mountMainLayoutAt('/dashboard')

    expect(navigationLink(wrapper, 'Characters')?.attributes('href')).toBe('/characters')
  })

  it('marks only the entry of the current route with aria-current page', async () => {
    const wrapper = await mountMainLayoutAt('/industry')

    const currentPageLinks = wrapper.findAll('a[aria-current="page"]')

    expect(currentPageLinks).toHaveLength(1)
    expect(currentPageLinks[0].attributes('href')).toBe('/industry')
    expect(navigationLink(wrapper, 'Dashboard')?.attributes('aria-current')).toBeUndefined()
    expect(navigationLink(wrapper, 'Group Industry')?.attributes('aria-current')).toBeUndefined()
  })

  it('keeps the collapsible group header as a button reflecting its expanded state', async () => {
    const wrapper = await mountMainLayoutAt('/dashboard')

    const groupHeader = () => wrapper.findAll('nav button').find(button => button.text().trim() === 'Production')

    expect(groupHeader()?.element.tagName).toBe('BUTTON')
    expect(groupHeader()?.attributes('aria-expanded')).toBe('true')

    await groupHeader()?.trigger('click')

    expect(groupHeader()?.attributes('aria-expanded')).toBe('false')
    expect(navigationLink(wrapper, 'Industry')).toBeUndefined()
  })
})
