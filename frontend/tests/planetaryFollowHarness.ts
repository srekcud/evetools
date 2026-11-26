import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import type { Component } from 'vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import { usePlanetaryStore, type PlanetaryRevenue } from '@/stores/planetary'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import type { PlannerCatalog } from '@/utils/planetaryPlanner'
import type { PlannerAccount } from '@/utils/planetaryPlanets'
import type { PlanTemplate } from '@/utils/planetaryTemplates'
import { BROADCAST_NODE, COOLANT, PLANNER_ACCOUNT, PLANNER_CATALOG, TOXIC_METALS, WATER } from './planetaryPlannerFixtures'
import { DEFAULT_TEMPLATE_SETTINGS, makeTemplate } from './planetaryTemplatesFixtures'

export const TEMPLATES_ENDPOINT = '/planetary/templates'

export const RING_TEMPLATE = makeTemplate(
  'tpl-ring',
  'Broadcast ring',
  [{ typeId: BROADCAST_NODE, targetPerDay: 36 }],
  { [WATER]: 'produce', [TOXIC_METALS]: 'produce' },
  DEFAULT_TEMPLATE_SETTINGS,
  '2026-11-22T10:00:00+00:00',
  true,
)

export const COOLANT_LINE_TEMPLATE = makeTemplate(
  'tpl-coolant-line',
  'Coolant line',
  [{ typeId: COOLANT, targetPerDay: 1_200 }],
  {},
  DEFAULT_TEMPLATE_SETTINGS,
  '2026-11-21T09:00:00+00:00',
)

export function accountWithFactories(factories: PlannerAccount['factories']): PlannerAccount {
  return { ...PLANNER_ACCOUNT, factories }
}

export function revenueOf(realisedIsk: number, days: number): PlanetaryRevenue {
  return {
    periodEnd: '2026-11-25',
    days,
    tiers: [
      { tier: 'P1', realisedIsk: 0, change: null, daily: [], contributors: [] },
      { tier: 'P2', realisedIsk, change: null, daily: [], contributors: [] },
      { tier: 'P3', realisedIsk: 0, change: null, daily: [], contributors: [] },
      { tier: 'P4', realisedIsk: 0, change: null, daily: [], contributors: [] },
    ],
    realisedIsk,
    realisedChange: null,
    realisedDaily: [],
    realisedContributors: [],
    estimate: { dailyIsk: 0, periodIsk: 0, contributors: [] },
    toFix: [],
    unsyncedWallets: [],
  }
}

export interface FollowScenario {
  catalog?: PlannerCatalog
  account?: PlannerAccount | Error
  templates?: PlanTemplate[]
  revenue?: PlanetaryRevenue
  locale?: 'en' | 'fr'
  saved?: PlanTemplate
  saveError?: Error
  patchError?: Error
}

export function serveFollowEndpoints(scenario: FollowScenario = {}): void {
  const catalog = scenario.catalog === undefined ? PLANNER_CATALOG : scenario.catalog
  const account = scenario.account === undefined ? PLANNER_ACCOUNT : scenario.account
  const templates = scenario.templates === undefined ? [] : scenario.templates
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string, options?: RequestInit) => {
    const method = options?.method ?? 'GET'
    if (endpoint === TEMPLATES_ENDPOINT && method === 'GET') return templates
    if (endpoint === TEMPLATES_ENDPOINT && method === 'POST') {
      if (scenario.saveError) throw scenario.saveError
      return scenario.saved
    }
    if (endpoint.startsWith(`${TEMPLATES_ENDPOINT}/`) && method === 'PATCH') {
      if (scenario.patchError) throw scenario.patchError
      const id = endpoint.slice(TEMPLATES_ENDPOINT.length + 1)
      const template = [...templates, ...(scenario.saved === undefined ? [] : [scenario.saved])].find(candidate => candidate.id === id)
      return { ...template, followed: JSON.parse(String(options?.body)).followed }
    }
    if (endpoint === '/planetary/planner/account') {
      if (account instanceof Error) throw account
      return { id: 'account', ...account }
    }
    if (endpoint.startsWith('/planetary/revenue')) {
      if (scenario.revenue === undefined) throw new Error('No revenue served')
      const days = Number(new URL(endpoint, 'http://localhost').searchParams.get('days'))
      return { ...scenario.revenue, days }
    }
    return { id: 'planner', ...catalog }
  }) as never)
}

export async function mountFollowed(component: Component, scenario: FollowScenario = {}) {
  setActivePinia(createPinia())
  serveFollowEndpoints(scenario)
  const store = usePlanetaryPlannerStore()
  const planetaryStore = usePlanetaryStore()
  await store.fetchCatalog()
  await store.fetchAccount()
  await store.fetchTemplates()
  if (scenario.revenue !== undefined) await planetaryStore.fetchRevenue(scenario.revenue.days)
  const wrapper = mount(component, {
    global: {
      plugins: [createI18n({ legacy: false, locale: scenario.locale === undefined ? 'en' : scenario.locale, messages: { en, fr } })],
      stubs: { RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return { wrapper, store, planetaryStore }
}

export function patchedBodies(): Array<{ endpoint: string, body: unknown }> {
  return vi.mocked(apiRequest).mock.calls
    .filter(call => (call[1] as RequestInit | undefined)?.method === 'PATCH')
    .map(call => ({ endpoint: call[0], body: JSON.parse(String((call[1] as RequestInit).body)) }))
}
