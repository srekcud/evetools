import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import ProjectSellOrdersSection from '@/components/group-industry/ProjectSellOrdersSection.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import type { ProjectSellOrders } from '@/stores/group-industry/types'
import { useSyncStore } from '@/stores/sync'
import { sellOrder } from '../../sellOrderFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-sabre'
const SELL_ORDERS_PATH = `/industry/projects/${PROJECT_ID}/sell-orders`

function sellOrdersOf(orderIds: number[]): ProjectSellOrders {
  return {
    projectId: PROJECT_ID,
    orders: orderIds.map(orderId => sellOrder({ orderId })),
    charactersToReconnect: [],
    syncedAt: '2026-11-10T12:15:00+00:00',
  }
}

function mountSection(): VueWrapper {
  return mount(ProjectSellOrdersSection, {
    props: { projectId: PROJECT_ID },
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
}

function readsOfSellOrders(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === SELL_ORDERS_PATH).length
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('ProjectSellOrdersSection', () => {
  it('reads the sell orders of the project when mounted and shows one row per order', async () => {
    vi.mocked(apiRequest).mockResolvedValue(sellOrdersOf([1, 2]))

    const section = mountSection()
    await flushPromises()

    expect({ reads: readsOfSellOrders(), rows: section.findAll('[data-testid="sell-order-row"]').length }).toEqual({ reads: 1, rows: 2 })
  })

  it('shows the reason when the orders cannot be read', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('You do not have access to this project'))

    const section = mountSection()
    await flushPromises()

    expect({
      error: section.get('[data-testid="sell-orders-error"]').text(),
      rows: section.findAll('[data-testid="sell-order-row"]').length,
    }).toEqual({ error: 'You do not have access to this project', rows: 0 })
  })

  it('reads the orders again when a character orders sync completes, and ignores the other sync types', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce(sellOrdersOf([1])).mockResolvedValueOnce(sellOrdersOf([1, 2, 3]))
    const section = mountSection()
    await flushPromises()
    const syncStore = useSyncStore()

    syncStore.syncStatus['blueprints'] = { syncType: 'blueprints', status: 'completed', progress: 100, message: null, data: null, timestamp: '2026-11-10T12:16:00+00:00' }
    await flushPromises()
    const readsAfterOtherSync = readsOfSellOrders()
    syncStore.syncStatus['character-orders'] = { syncType: 'character-orders', status: 'completed', progress: 100, message: null, data: null, timestamp: '2026-11-10T12:20:00+00:00' }
    await flushPromises()

    expect({ readsAfterOtherSync, reads: readsOfSellOrders(), rows: section.findAll('[data-testid="sell-order-row"]').length }).toEqual({
      readsAfterOtherSync: 1,
      reads: 2,
      rows: 3,
    })
  })
})
