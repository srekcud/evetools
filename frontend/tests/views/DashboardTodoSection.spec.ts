import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import Dashboard from '@/views/Dashboard.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'
import {
  ACTIVE_TRITANIUM_ALERT,
  INVALID_TOKEN,
  PENDING,
  TRIGGERED_MEXALLON_ALERT,
  TRIGGERED_PYERITE_ALERT,
  planetaryStats,
  serverError,
  sourcesAnswering,
  todoUser,
  type SourceAnswer,
} from '../composables/todoSources'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const COMPLETE_WALLETS = {
  '@context': '/api/contexts/Wallet',
  '@id': '/api/me/wallets',
  '@type': 'Wallet',
  id: 'current',
  totalBalance: 2_480_000.25,
  incomplete: false,
  wallets: [
    { characterId: '00000000-0000-4000-8000-000000230001', characterName: 'Ilyra Castell', isMain: true, balance: 1_980_000.25 },
    { characterId: '00000000-0000-4000-8000-000000230002', characterName: 'Doran Vex', isMain: false, balance: 500_000 },
  ],
}

async function mountDashboard(sources: { locale?: 'en' | 'fr'; user?: User; planetaryStats?: SourceAnswer[]; marketAlerts?: SourceAnswer[] }): Promise<VueWrapper> {
  const user = sources.user ?? todoUser()
  vi.mocked(apiRequest).mockImplementation(sourcesAnswering({
    user,
    planetaryStats: sources.planetaryStats ?? [planetaryStats()],
    marketAlerts: sources.marketAlerts ?? [[ACTIVE_TRITANIUM_ALERT]],
  }))
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(COMPLETE_WALLETS), { status: 200 })))

  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = user

  const i18n = createI18n({ legacy: false, locale: sources.locale ?? 'en', messages: { en, fr } })
  const wrapper = mount(Dashboard, {
    global: {
      plugins: [pinia, i18n],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        LoadingSpinner: true,
        RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
      },
    },
  })
  await flushPromises()
  return wrapper
}

function todoSection(wrapper: VueWrapper): DOMWrapper<Element> {
  const section = wrapper.find('[data-testid="dashboard-todo"]')
  expect(section.exists()).toBe(true)
  return section
}

function todoRows(wrapper: VueWrapper): { text: string; href: string | null }[] {
  return todoSection(wrapper).findAll('[data-testid="dashboard-todo-item"]').map(row => {
    const link = row.element.tagName === 'A' ? row.element : row.element.querySelector('a')
    return { text: row.text().replace(/\s+/g, ' ').trim(), href: link?.getAttribute('href') ?? null }
  })
}

function sectionText(wrapper: VueWrapper): string {
  return todoSection(wrapper).text().replace(/\s+/g, ' ')
}

function elementWithText(wrapper: VueWrapper, selector: string, text: string): Element {
  const found = wrapper.findAll(selector).find(element => element.text() === text)
  if (!found) throw new Error(`No ${selector} with text ${text}`)
  return found.element
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('Dashboard to-do section', () => {
  it('is the first block, above the KPI cards and the activity feed, outside their columns', async () => {
    const wrapper = await mountDashboard({ planetaryStats: [planetaryStats({ expiringExtractors: 2 })] })

    const section = todoSection(wrapper).element
    const charactersKpi = elementWithText(wrapper, 'span', 'Characters')
    const activityHeading = elementWithText(wrapper, 'h3', 'Activity')

    expect(section.compareDocumentPosition(charactersKpi) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(section.compareDocumentPosition(activityHeading) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(section.parentElement?.contains(charactersKpi)).toBe(true)
    expect(section.parentElement?.contains(activityHeading)).toBe(true)
  })

  it('shows all caught up and no row when nothing needs action', async () => {
    const wrapper = await mountDashboard({})

    expect(sectionText(wrapper)).toContain('To do')
    expect(sectionText(wrapper)).toContain('All caught up')
    expect(todoRows(wrapper)).toEqual([])
  })

  it('shows two PI extractors expiring within 24 h linking to planetary', async () => {
    const wrapper = await mountDashboard({ planetaryStats: [planetaryStats({ expiringExtractors: 2 })] })

    expect(todoRows(wrapper)).toEqual([{ text: '2 PI extractors expire within 24 h', href: '/planetary' }])
    expect(sectionText(wrapper)).not.toContain('All caught up')
  })

  it('shows the PI extractors as unknown without any zero when the planetary stats fail', async () => {
    const wrapper = await mountDashboard({ planetaryStats: [serverError()] })

    expect(todoRows(wrapper)).toEqual([{ text: 'PI extractors: unknown (loading failed)', href: '/planetary' }])
    expect(sectionText(wrapper)).not.toMatch(/\b0\b/)
    expect(sectionText(wrapper)).not.toContain('All caught up')
  })

  it('shows at least one character to reconnect without a name when the authorization is revoked', async () => {
    const wrapper = await mountDashboard({ user: todoUser({ authStatus: 'invalid' }) })

    const rows = todoRows(wrapper)
    expect(rows).toHaveLength(1)
    expect(rows[0].text).toContain('At least 1 character to reconnect')
    expect(rows[0].text).not.toContain('Ilyra Castell')
    expect(rows[0].text).not.toContain('Doran Vex')
    expect(rows[0].href).toBe('/characters')
  })

  it('shows the four categories in fixed order with their links in English', async () => {
    const wrapper = await mountDashboard({
      user: todoUser({ doran: INVALID_TOKEN }),
      planetaryStats: [planetaryStats({ expiredExtractors: 1, expiringExtractors: 3 })],
      marketAlerts: [[TRIGGERED_PYERITE_ALERT, ACTIVE_TRITANIUM_ALERT, TRIGGERED_MEXALLON_ALERT]],
    })

    const rows = todoRows(wrapper)
    expect(rows.map(row => row.href)).toEqual(['/characters', '/planetary', '/planetary', '/market?tab=alerts'])
    expect(rows[0].text).toContain('1 character to reconnect')
    expect(rows[0].text).toContain('Doran Vex')
    expect(rows[0].text).not.toContain('Ilyra Castell')
    expect(rows.slice(1).map(row => row.text)).toEqual([
      '1 PI extractor stopped',
      '3 PI extractors expire within 24 h',
      '2 price alerts triggered',
    ])
  })

  it('shows the four categories in French under the title À faire', async () => {
    const wrapper = await mountDashboard({
      locale: 'fr',
      user: todoUser({ doran: INVALID_TOKEN }),
      planetaryStats: [planetaryStats({ expiredExtractors: 1, expiringExtractors: 3 })],
      marketAlerts: [[TRIGGERED_PYERITE_ALERT, ACTIVE_TRITANIUM_ALERT, TRIGGERED_MEXALLON_ALERT]],
    })

    const rows = todoRows(wrapper)
    expect(sectionText(wrapper)).toContain('À faire')
    expect(rows[0].text).toContain('1 personnage à reconnecter')
    expect(rows.slice(1).map(row => row.text)).toEqual([
      '1 extracteur PI arrêté',
      '3 extracteurs PI expirent dans moins de 24 h',
      '2 alertes de prix déclenchées',
    ])
  })

  it('shows the price alerts as unknown linking to the market alerts tab when the alerts fail', async () => {
    const wrapper = await mountDashboard({ marketAlerts: [serverError()] })

    expect(todoRows(wrapper)).toEqual([{ text: 'Price alerts: unknown (loading failed)', href: '/market?tab=alerts' }])
    expect(sectionText(wrapper)).not.toContain('All caught up')
  })

  it('shows a loading indicator and not all caught up while the planetary stats have not answered', async () => {
    const wrapper = await mountDashboard({ planetaryStats: [PENDING] })

    expect(todoSection(wrapper).find('[data-testid="dashboard-todo-loading"]').exists()).toBe(true)
    expect(sectionText(wrapper)).not.toContain('All caught up')
  })
})
