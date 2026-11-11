import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import MoonTaxTab from '@/components/ledger/moon-tax/MoonTaxTab.vue'
import type { CorporationRoleAccess } from '@/composables/useCorporationRoleAccess'
import { DENIED_CORPORATION, SYNCED_CORPORATION, response } from './moonChunksHarness'
import {
  CORPORATION_A,
  CORPORATION_B,
  MINE_DUE,
  NOW,
  corporationPath,
  defaultRoutes,
  donationsPath,
  i18nPlugin,
  moonTaxCorporation,
  requestLines,
  route,
  statementsPath,
} from './moonTaxHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
  authFetch: vi.fn(),
}))

enableAutoUnmount(afterEach)

const MINE_PATH = '/mining/moon-tax/mine'

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(NOW))
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

function accessPath(corporationId: number): string {
  return `/mining/corporation-role-access/${corporationId}?feature=moon-tax`
}

function recheckPath(corporationId: number): string {
  return `/mining/corporation-role-access/${corporationId}/recheck?feature=moon-tax`
}

function held(corporationId: number): CorporationRoleAccess {
  return { corporationId, status: 'held', requiredRole: 'Accountant', characters: [] }
}

function roleMissing(corporationId: number): CorporationRoleAccess {
  return {
    corporationId,
    status: 'role_missing',
    requiredRole: 'Accountant',
    characters: [{ characterId: 2112000001, name: 'Ilyra Voss', isMain: true, state: 'without_role', roles: ['Auditor'] }],
  }
}

function reconnect(corporationId: number): CorporationRoleAccess {
  return {
    corporationId,
    status: 'reconnect',
    requiredRole: 'Accountant',
    characters: [{ characterId: 2112000001, name: 'Ilyra Voss', isMain: true, state: 'reconnect', roles: null }],
  }
}

function routesFor(accessA: CorporationRoleAccess, accessB: CorporationRoleAccess): Record<string, unknown> {
  return {
    ...defaultRoutes(response(SYNCED_CORPORATION, DENIED_CORPORATION)),
    [`GET ${accessPath(CORPORATION_A)}`]: accessA,
    [`GET ${accessPath(CORPORATION_B)}`]: accessB,
    [`GET ${corporationPath(CORPORATION_B)}`]: moonTaxCorporation({ corporationId: CORPORATION_B }),
    [`GET ${statementsPath(CORPORATION_B)}`]: [],
    [`GET ${donationsPath(CORPORATION_B)}`]: [],
    [`GET ${MINE_PATH}`]: [MINE_DUE],
  }
}

async function mountTab(routes: Record<string, unknown>) {
  setActivePinia(createPinia())
  route(routes)
  const wrapper = mount(MoonTaxTab, { global: { plugins: [i18nPlugin()], stubs: { RouterLink: RouterLinkStub } } })
  await flushPromises()
  return wrapper
}

describe('MoonTaxTab without any manageable corporation', () => {
  it('opens My tax by default and loads it', async () => {
    const wrapper = await mountTab(routesFor(roleMissing(CORPORATION_A), roleMissing(CORPORATION_B)))

    expect(wrapper.get('[data-moon-tax-view="miner"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.get('[data-moon-tax-view="manager"]').attributes('aria-pressed')).toBe('false')
    expect(requestLines()).toContain(`GET ${MINE_PATH}`)
  })

  it('shows a single empty state with one line per corporation in the Manager view and no corporation panel', async () => {
    const wrapper = await mountTab(routesFor(roleMissing(CORPORATION_A), reconnect(CORPORATION_B)))

    await wrapper.get('[data-moon-tax-view="manager"]').trigger('click')

    const empty = wrapper.get('[data-moon-tax-none-manageable]')
    expect(empty.text()).toContain('None of your corporations')
    const lines = empty.findAll('[data-blocked-line]')
    expect(lines.map(line => line.attributes('data-corporation-id'))).toEqual([String(CORPORATION_A), String(CORPORATION_B)])
    expect(lines[0].text()).toContain('Accountant')
    expect(lines[1].attributes('data-access-state')).toBe('reconnect')
    expect(wrapper.findAll('[data-moon-tax-corporation]')).toHaveLength(0)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('keeps the full access card of each corporation inside its collapsed line', async () => {
    const wrapper = await mountTab(routesFor(roleMissing(CORPORATION_A), roleMissing(CORPORATION_B)))
    await wrapper.get('[data-moon-tax-view="manager"]').trigger('click')

    const line = wrapper.get(`[data-blocked-line][data-corporation-id="${CORPORATION_A}"]`)

    expect(line.element.tagName).toBe('DETAILS')
    expect(line.attributes('open')).toBeUndefined()
    expect(line.find('[data-access-required]').exists()).toBe(true)
  })
})

describe('MoonTaxTab with a manageable corporation', () => {
  it('keeps Manager by default with one panel and groups the blocked corporation on a collapsed line', async () => {
    const wrapper = await mountTab(routesFor(held(CORPORATION_A), roleMissing(CORPORATION_B)))

    expect(wrapper.get('[data-moon-tax-view="manager"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.findAll('[data-moon-tax-corporation]').map(panel => panel.attributes('data-moon-tax-corporation'))).toEqual([String(CORPORATION_A)])
    const blocked = wrapper.get('[data-moon-tax-blocked]')
    expect(blocked.element.tagName).toBe('DETAILS')
    expect(blocked.attributes('open')).toBeUndefined()
    expect(blocked.get('summary').text()).toBe('1 corporation without the Accountant role')
    expect(wrapper.find('[data-moon-tax-none-manageable]').exists()).toBe(false)
  })

  it('counts several blocked corporations', async () => {
    const wrapper = await mountTab({
      ...routesFor(held(CORPORATION_A), roleMissing(CORPORATION_B)),
      ...defaultRoutes(response(SYNCED_CORPORATION, DENIED_CORPORATION, { ...DENIED_CORPORATION, corporationId: 98000003 })),
      [`GET ${accessPath(CORPORATION_A)}`]: held(CORPORATION_A),
      [`GET ${accessPath(CORPORATION_B)}`]: roleMissing(CORPORATION_B),
      [`GET ${accessPath(98000003)}`]: roleMissing(98000003),
    })

    expect(wrapper.get('[data-moon-tax-blocked] summary').text()).toBe('2 corporations without the Accountant role')
  })

  it('mounts no manager panel for a blocked corporation and reads none of its statements', async () => {
    const wrapper = await mountTab(routesFor(held(CORPORATION_A), roleMissing(CORPORATION_B)))

    expect(wrapper.find(`[data-moon-tax-corporation="${CORPORATION_B}"]`).exists()).toBe(false)
    expect(requestLines()).not.toContain(`GET ${corporationPath(CORPORATION_B)}`)
  })

  it('treats a corporation whose access cannot be read as manageable, leaving the panel to say what failed', async () => {
    const wrapper = await mountTab({ ...routesFor(held(CORPORATION_A), roleMissing(CORPORATION_B)), [`GET ${accessPath(CORPORATION_B)}`]: new Error('network') })

    expect(wrapper.findAll('[data-moon-tax-corporation]').map(panel => panel.attributes('data-moon-tax-corporation'))).toEqual([String(CORPORATION_A), String(CORPORATION_B)])
    expect(wrapper.find('[data-moon-tax-blocked]').exists()).toBe(false)
  })
})

describe('MoonTaxTab recheck', () => {
  it('reads the access again and lets the corporation in once the role is there', async () => {
    const routes = routesFor(held(CORPORATION_A), roleMissing(CORPORATION_B))
    const wrapper = await mountTab({ ...routes, [`POST ${recheckPath(CORPORATION_B)}`]: held(CORPORATION_B) })

    await wrapper.get(`[data-moon-tax-blocked] [data-recheck]`).trigger('click')
    await flushPromises()

    expect(requestLines()).toContain(`POST ${recheckPath(CORPORATION_B)}`)
    expect(wrapper.findAll('[data-moon-tax-corporation]').map(panel => panel.attributes('data-moon-tax-corporation'))).toEqual([String(CORPORATION_A), String(CORPORATION_B)])
    expect(wrapper.find('[data-moon-tax-blocked]').exists()).toBe(false)
  })

  it('keeps the corporation blocked when the recheck still finds no role', async () => {
    const routes = routesFor(held(CORPORATION_A), roleMissing(CORPORATION_B))
    const wrapper = await mountTab({ ...routes, [`POST ${recheckPath(CORPORATION_B)}`]: roleMissing(CORPORATION_B) })

    await wrapper.get(`[data-moon-tax-blocked] [data-recheck]`).trigger('click')
    await flushPromises()

    expect(wrapper.find(`[data-moon-tax-corporation="${CORPORATION_B}"]`).exists()).toBe(false)
    expect(wrapper.find('[data-moon-tax-blocked]').exists()).toBe(true)
  })
})
