import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import MoonChunksTab from '@/components/ledger/MoonChunksTab.vue'
import type { CorporationRoleAccess } from '@/composables/useCorporationRoleAccess'
import type { CorporationMiningExtractionsResponse } from '@/composables/useCorporationMiningExtractions'
import { i18nPlugin } from './miningLedgerHarness'
import { CORPORATION_A, CORPORATION_B, DENIED_CORPORATION, NOW, SYNCED_CORPORATION, corporation, response } from './moonChunksHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const EXTRACTIONS_PATH = '/mining/corporation-extractions'
const BLOCKED_WITHOUT_HISTORY = corporation({ corporationId: CORPORATION_B, access: 'denied', lastSuccessAt: null, extractions: [] })

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(NOW))
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

function accessPath(corporationId: number): string {
  return `/mining/corporation-role-access/${corporationId}?feature=extractions`
}

function recheckPath(corporationId: number): string {
  return `/mining/corporation-role-access/${corporationId}/recheck?feature=extractions`
}

function roleMissing(corporationId: number): CorporationRoleAccess {
  return {
    corporationId,
    status: 'role_missing',
    requiredRole: 'Station_Manager',
    characters: [{ characterId: 2112000001, name: 'Ilyra Voss', isMain: true, state: 'without_role', roles: ['Accountant'] }],
  }
}

function held(corporationId: number): CorporationRoleAccess {
  return { corporationId, status: 'held', requiredRole: 'Station_Manager', characters: [] }
}

function reconnect(corporationId: number): CorporationRoleAccess {
  return {
    corporationId,
    status: 'reconnect',
    requiredRole: 'Station_Manager',
    characters: [{ characterId: 2112000001, name: 'Ilyra Voss', isMain: true, state: 'reconnect', roles: null }],
  }
}

async function mountTab(loaded: CorporationMiningExtractionsResponse, routes: Record<string, unknown> = {}) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === EXTRACTIONS_PATH) return loaded
    const key = `${options?.method?.toUpperCase() ?? 'GET'} ${endpoint}`
    if (key in routes) return routes[key]
    return null
  })
  const wrapper = mount(MoonChunksTab, { global: { plugins: [i18nPlugin()], stubs: { RouterLink: RouterLinkStub } } })
  await flushPromises()
  return wrapper
}

function calls(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint, options]) => `${options?.method?.toUpperCase() ?? 'GET'} ${endpoint}`)
}

describe('MoonChunksTab corporation without access and without history', () => {
  it('folds the corporation under the accessible ones, closed by default', async () => {
    const wrapper = await mountTab(response(BLOCKED_WITHOUT_HISTORY, SYNCED_CORPORATION), { [`GET ${accessPath(CORPORATION_B)}`]: roleMissing(CORPORATION_B) })

    const blocked = wrapper.get('[data-blocked-corporation]')
    expect(blocked.element.tagName).toBe('DETAILS')
    expect(blocked.attributes('open')).toBeUndefined()
    expect(blocked.attributes('data-corporation')).toBe(String(CORPORATION_B))
    const order = wrapper.findAll('[data-corporation]').map(section => section.attributes('data-corporation'))
    expect(order).toEqual([String(CORPORATION_A), String(CORPORATION_B)])
  })

  it('keeps a corporation without access but with a readable history in the main list', async () => {
    const wrapper = await mountTab(response(DENIED_CORPORATION, SYNCED_CORPORATION), { [`GET ${accessPath(CORPORATION_B)}`]: roleMissing(CORPORATION_B) })

    expect(wrapper.find('[data-blocked-corporation]').exists()).toBe(false)
    expect(wrapper.find(`[data-corporation="${CORPORATION_B}"] [data-extraction-id]`).exists()).toBe(true)
  })

  it('does not fold a corporation never synced or in ESI error', async () => {
    const wrapper = await mountTab(response(corporation({ access: 'never_synced', lastSuccessAt: null }), corporation({ corporationId: CORPORATION_B, access: 'error', lastSuccessAt: null })))

    expect(wrapper.find('[data-blocked-corporation]').exists()).toBe(false)
  })
})

describe('MoonChunksTab access card', () => {
  it('replaces the access sentence by the card of the missing Station_Manager role, without an alert', async () => {
    const wrapper = await mountTab(response(DENIED_CORPORATION), { [`GET ${accessPath(CORPORATION_B)}`]: roleMissing(CORPORATION_B) })

    const card = wrapper.get(`[data-corporation="${CORPORATION_B}"] [data-access-required]`)
    expect(card.attributes('data-access-state')).toBe('role_missing')
    expect(card.text()).toContain('Station_Manager')
    expect(card.findAll('[data-access-character]')).toHaveLength(1)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.find(`[data-corporation="${CORPORATION_B}"] [data-access-state="denied"]`).exists()).toBe(false)
    expect(calls()).toContain(`GET ${accessPath(CORPORATION_B)}`)
  })

  it('shows the reconnection state with a link to the characters page', async () => {
    const wrapper = await mountTab(response(corporation({ access: 'reconnect', lastSuccessAt: null, extractions: [] })), { [`GET ${accessPath(CORPORATION_A)}`]: reconnect(CORPORATION_A) })

    expect(wrapper.get('[data-access-required]').attributes('data-access-state')).toBe('reconnect')
    expect(wrapper.getComponent(RouterLinkStub).props('to')).toEqual({ name: 'characters' })
  })

  it('keeps the last successful sync visible inside the card', async () => {
    const wrapper = await mountTab(response(DENIED_CORPORATION), { [`GET ${accessPath(CORPORATION_B)}`]: roleMissing(CORPORATION_B) })

    expect(wrapper.get('[data-access-required] [data-last-success-at]').text()).toBe('2026-11-04 10:00')
  })

  it('falls back to the access sentence while the detail of the access is not available', async () => {
    const wrapper = await mountTab(response(DENIED_CORPORATION))

    expect(wrapper.get(`[data-corporation="${CORPORATION_B}"] [data-access-state]`).attributes('data-access-state')).toBe('denied')
    expect(wrapper.find('[data-access-required]').exists()).toBe(false)
  })

  it('does not read the access detail of a corporation read successfully', async () => {
    await mountTab(response(SYNCED_CORPORATION))

    expect(calls().filter(call => call.includes('corporation-role-access'))).toEqual([])
  })

  it('syncs the extractions again after a recheck that finds the role', async () => {
    const wrapper = await mountTab(response(DENIED_CORPORATION), {
      [`GET ${accessPath(CORPORATION_B)}`]: roleMissing(CORPORATION_B),
      [`POST ${recheckPath(CORPORATION_B)}`]: held(CORPORATION_B),
    })

    await wrapper.get('[data-access-required] [data-recheck]').trigger('click')
    await flushPromises()

    expect(calls()).toContain(`POST ${recheckPath(CORPORATION_B)}`)
    expect(calls()).toContain(`POST ${EXTRACTIONS_PATH}/sync`)
  })

  it('does not sync again after a recheck that still finds no role', async () => {
    const wrapper = await mountTab(response(DENIED_CORPORATION), {
      [`GET ${accessPath(CORPORATION_B)}`]: roleMissing(CORPORATION_B),
      [`POST ${recheckPath(CORPORATION_B)}`]: roleMissing(CORPORATION_B),
    })

    await wrapper.get('[data-access-required] [data-recheck]').trigger('click')
    await flushPromises()

    expect(calls()).toContain(`POST ${recheckPath(CORPORATION_B)}`)
    expect(calls()).not.toContain(`POST ${EXTRACTIONS_PATH}/sync`)
  })
})
