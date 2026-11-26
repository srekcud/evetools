import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import PiPlannerRanking from '@/components/planetary/PiPlannerRanking.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import type { PlannerCatalog } from '@/utils/planetaryPlanner'
import type { PlannerAccount } from '@/utils/planetaryPlanets'
import type { PlannerMarket } from '@/utils/planetaryRanking'
import { PLANNER_ACCOUNT, PLANNER_CATALOG, PLANNER_MARKET } from './planetaryPlannerFixtures'

export interface RankingScenario {
  catalog?: PlannerCatalog
  market?: PlannerMarket | Error
  account?: PlannerAccount
}

export async function mountRanking(scenario: RankingScenario = {}) {
  const catalog = scenario.catalog === undefined ? PLANNER_CATALOG : scenario.catalog
  const market = scenario.market === undefined ? PLANNER_MARKET : scenario.market
  const account = scenario.account === undefined ? PLANNER_ACCOUNT : scenario.account
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
    if (endpoint === '/planetary/planner/account') return { id: 'account', ...account }
    if (endpoint === '/planetary/planner/market') {
      if (market instanceof Error) throw market
      return { id: 'market', ...market }
    }
    return { id: 'planner', ...catalog }
  }) as never)
  const store = usePlanetaryPlannerStore()
  await store.fetchCatalog()
  await store.fetchAccount()
  await store.fetchMarket()
  const wrapper = mount(PiPlannerRanking, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return { wrapper, store }
}

export type RankingWrapper = Awaited<ReturnType<typeof mountRanking>>['wrapper']

export function rankingRows(wrapper: RankingWrapper) {
  return wrapper.findAll('[data-testid="ranking-row"]')
}

export function rankingTypeIds(wrapper: RankingWrapper): number[] {
  return rankingRows(wrapper).map(row => Number(row.attributes('data-type-id')))
}

export function rowOf(wrapper: RankingWrapper, typeId: number) {
  return wrapper.get(`[data-testid="ranking-row"][data-type-id="${typeId}"]`)
}

export function cellOf(wrapper: RankingWrapper, typeId: number, testId: string) {
  return rowOf(wrapper, typeId).get(`[data-testid="${testId}"]`)
}

export function compact(text: string): string {
  return text.replace(/\s+/g, '')
}

export function hasFigure(text: string, figure: string): boolean {
  const escaped = figure.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  return new RegExp(`(?<![\\d.,])${escaped}(?![\\d.,])`).test(text)
}
