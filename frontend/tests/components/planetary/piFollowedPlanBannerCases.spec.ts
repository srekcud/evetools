import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import PiFollowedPlanBanner from '@/components/planetary/PiFollowedPlanBanner.vue'
import en from '@/i18n/locales/en.json'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { BROADCAST_NODE, COOLANT, PLANNER_CATALOG, TOXIC_METALS, WATER } from '../../planetaryPlannerFixtures'
import { asFollowed, makeTemplate } from '../../planetaryTemplatesFixtures'
import { COOLANT_LINE_TEMPLATE, RING_TEMPLATE, accountWithFactories, mountFollowed, serveFollowEndpoints, type FollowScenario } from '../../planetaryFollowHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const UNKNOWN_FIGURE = '—'
const RING_ACCOUNT = accountWithFactories({ P1: 20, P2: 9, P3: 3, P4: 2 })
const LONG_PLAN_NAME = 'Broadcast ring of the Outer Rim Consortium, extended production run for the whole alliance logistics wing'

type Mounted = Awaited<ReturnType<typeof mountFollowed>>

async function mountBanner(scenario: FollowScenario = {}) {
  return mountFollowed(PiFollowedPlanBanner, { templates: [RING_TEMPLATE, COOLANT_LINE_TEMPLATE], account: RING_ACCOUNT, ...scenario })
}

async function mountBannerWhileCatalogLoads() {
  setActivePinia(createPinia())
  serveFollowEndpoints({ templates: [RING_TEMPLATE], account: RING_ACCOUNT })
  const served = vi.mocked(apiRequest).getMockImplementation()
  if (served === undefined) throw new Error('The harness serves no API')
  vi.mocked(apiRequest).mockImplementation(((endpoint: string, options?: RequestInit) => {
    if (endpoint === '/planetary/planner') return new Promise(() => {})
    return served(endpoint, options)
  }) as never)
  const store = usePlanetaryPlannerStore()
  void store.fetchCatalog()
  await store.fetchAccount()
  await store.fetchTemplates()
  const wrapper = mount(PiFollowedPlanBanner, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: { RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return { wrapper, store }
}

function tierRows(wrapper: Mounted['wrapper']) {
  return wrapper.findAll('[data-testid="followed-plan-tier"]')
}

function gapCell(wrapper: Mounted['wrapper'], tier: string) {
  return wrapper.get(`[data-testid="followed-plan-tier"][data-tier="${tier}"] [data-testid="followed-plan-gap"]`)
}

function gapTexts(wrapper: Mounted['wrapper']): string[] {
  return ['P1', 'P2', 'P3', 'P4'].map(tier => gapCell(wrapper, tier).text())
}

function colorOf(wrapper: Mounted['wrapper'], tier: string): string[] {
  return gapCell(wrapper, tier).classes().filter(name => name.startsWith('text-'))
}

describe('PiFollowedPlanBanner with no factory built', () => {
  it('shows the four tiers of the ring plan as missing with the whole plan: missing 18, 12, 3 and 2 against 0 built', async () => {
    const { wrapper } = await mountBanner({ account: accountWithFactories({ P1: 0, P2: 0, P3: 0, P4: 0 }) })
    const column = (testId: string) => tierRows(wrapper).map(row => row.get(`[data-testid="${testId}"]`).text())

    expect(tierRows(wrapper).map(row => row.attributes('data-tier'))).toEqual(['P1', 'P2', 'P3', 'P4'])
    expect(tierRows(wrapper).map(row => row.attributes('data-state'))).toEqual(['missing', 'missing', 'missing', 'missing'])
    expect(gapTexts(wrapper)).toEqual(['missing 18', 'missing 12', 'missing 3', 'missing 2'])
    expect(column('followed-plan-planned')).toEqual(['18', '12', '3', '2'])
    expect(column('followed-plan-real')).toEqual(['0', '0', '0', '0'])
  })

  it('shows only the P2 line, as unknown with 10 planned and no built figure, for the coolant plan while the account cannot be read', async () => {
    const { wrapper } = await mountBanner({ templates: [{ ...RING_TEMPLATE, followed: false }, asFollowed(COOLANT_LINE_TEMPLATE)], account: new Error('account unavailable') })

    expect(tierRows(wrapper).map(row => row.attributes('data-tier'))).toEqual(['P2'])
    expect(tierRows(wrapper)[0].attributes('data-state')).toBe('unknown')
    expect(gapCell(wrapper, 'P2').text()).toBe('unknown')
    expect(tierRows(wrapper)[0].get('[data-testid="followed-plan-planned"]').text()).toBe('10')
    expect(tierRows(wrapper)[0].get('[data-testid="followed-plan-real"]').text()).toBe(UNKNOWN_FIGURE)
  })
})

describe('PiFollowedPlanBanner gap wording and colours', () => {
  it('colours a missing gap red, a free gap sky blue and an ok gap green', async () => {
    const { wrapper } = await mountBanner()

    expect(colorOf(wrapper, 'P1')).toEqual(['text-sky-400'])
    expect(colorOf(wrapper, 'P2')).toEqual(['text-red-400'])
    expect(colorOf(wrapper, 'P3')).toEqual(['text-emerald-400'])
    expect(colorOf(wrapper, 'P4')).toEqual(['text-emerald-400'])
  })

  it('colours an unknown gap grey', async () => {
    const { wrapper } = await mountBanner({ account: new Error('account unavailable') })

    expect(['P1', 'P2', 'P3', 'P4'].map(tier => colorOf(wrapper, tier))).toEqual([['text-slate-500'], ['text-slate-500'], ['text-slate-500'], ['text-slate-500']])
  })

  it('writes the singular and the plural of the gaps in English: 1 free, missing 1, 2 free, ok', async () => {
    const { wrapper } = await mountBanner({ account: accountWithFactories({ P1: 19, P2: 11, P3: 5, P4: 2 }) })

    expect(gapTexts(wrapper)).toEqual(['1 free', 'missing 1', '2 free', 'ok'])
  })

  it('writes the singular and the plural of the gaps in French: 1 libre, manque 1, 2 libres, ok', async () => {
    const { wrapper } = await mountBanner({ locale: 'fr', account: accountWithFactories({ P1: 19, P2: 11, P3: 5, P4: 2 }) })

    expect(gapTexts(wrapper)).toEqual(['1 libre', 'manque 1', '2 libres', 'ok'])
  })
})

describe('PiFollowedPlanBanner while the catalog is not loaded', () => {
  it('says the plan is loading', async () => {
    const { wrapper, store } = await mountBannerWhileCatalogLoads()

    expect(store.catalog).toBeNull()
    expect(store.followedTemplate?.id).toBe('tpl-ring')
    expect(wrapper.get('[data-testid="followed-plan-banner"]').attributes('data-state')).toBe('loading')
  })

  it('disables the Open the plan button', async () => {
    const { wrapper } = await mountBannerWhileCatalogLoads()

    expect(wrapper.get('[data-testid="followed-plan-open"]').attributes('disabled')).toBeDefined()
  })

  it('does not ask to open the planner and loads nothing into the plan when the player clicks Open the plan', async () => {
    const { wrapper, store } = await mountBannerWhileCatalogLoads()

    await wrapper.get('[data-testid="followed-plan-open"]').trigger('click')
    await flushPromises()

    expect(wrapper.emitted('open')).toBeUndefined()
    expect(store.productions).toEqual([])
  })

  it('keeps the Open the plan button disabled when the catalog is empty and could not be loaded', async () => {
    const { wrapper, store } = await mountBanner({ catalog: { ...PLANNER_CATALOG, recipes: [] }, templates: [RING_TEMPLATE] })

    expect(store.catalog).toBeNull()
    expect(wrapper.get('[data-testid="followed-plan-open"]').attributes('disabled')).toBeDefined()
  })

  it('does not say the plan is loading and enables the button once the catalog is loaded', async () => {
    const { wrapper } = await mountBanner()

    expect(wrapper.get('[data-testid="followed-plan-banner"]').attributes('data-state')).not.toBe('loading')
    expect(wrapper.get('[data-testid="followed-plan-open"]').attributes('disabled')).toBeUndefined()
  })
})

describe('PiFollowedPlanBanner with a long name and a changing list', () => {
  it('truncates a long plan name and keeps it whole in the title', async () => {
    const longPlan = makeTemplate('tpl-long', LONG_PLAN_NAME, [{ typeId: BROADCAST_NODE, targetPerDay: 36 }], { [WATER]: 'produce', [TOXIC_METALS]: 'produce' }, undefined, '2026-11-23T10:00:00+00:00', true)
    const { wrapper } = await mountBanner({ templates: [longPlan] })
    const name = wrapper.get('[data-testid="followed-plan-name"]')

    expect(name.text()).toBe(LONG_PLAN_NAME)
    expect(name.attributes('title')).toBe(LONG_PLAN_NAME)
    expect(name.classes()).toContain('truncate')
  })

  it('replaces the current plan of the planner by the followed one without asking for a confirmation', async () => {
    const { wrapper, store } = await mountBanner()
    store.addProduction(COOLANT, 1_200)
    store.setChoice(COOLANT, 'buy')

    await wrapper.get('[data-testid="followed-plan-open"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.emitted('open')).toHaveLength(1)
    expect(store.productions).toEqual([{ typeId: BROADCAST_NODE, targetPerDay: 36 }])
    expect(store.choices).toEqual({ [WATER]: 'produce', [TOXIC_METALS]: 'produce' })
  })

  it('disappears when the followed template is deleted, with no plan left to follow', async () => {
    const { wrapper, store } = await mountBanner()

    await store.deleteTemplate('tpl-ring')
    await flushPromises()

    expect(store.followedTemplate).toBeNull()
    expect(wrapper.find('[data-testid="followed-plan-banner"]').exists()).toBe(false)
  })
})
