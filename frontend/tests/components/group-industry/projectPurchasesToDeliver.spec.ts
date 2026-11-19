import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectPurchasesToDeliver from '@/components/group-industry/ProjectPurchasesToDeliver.vue'
import { apiRequest } from '@/services/api'
import { useGroupCourierStore } from '@/stores/group-industry/courier'
import type { ProjectPurchasesToDeliver as PurchasesToDeliverData, PurchaseToDeliverRow } from '@/stores/group-industry/types'
import { LONG_SYSTEM_NAME, readyCourier, TRITANIUM_TYPE_ID } from '../../courierFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'
const DELIVERIES_PATH = `/industry/projects/${PROJECT_ID}/purchases-to-deliver`
const HUB_SOLAR_SYSTEM_ID = 30001001
const NOCXIUM_TYPE_ID = 38

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

function row(overrides: Partial<PurchaseToDeliverRow>): PurchaseToDeliverRow {
  return {
    bomLineId: 'line-tritanium',
    typeId: TRITANIUM_TYPE_ID,
    typeName: 'Tritanium',
    originKind: 'other',
    solarSystemId: HUB_SOLAR_SYSTEM_ID,
    solarSystemName: 'C-J6MT',
    purchasedQuantity: 1000,
    missingQuantity: 600,
    deliveryState: 'partial',
    ...overrides,
  }
}

function deliveries(rows: PurchaseToDeliverRow[], overrides: Partial<PurchasesToDeliverData> = {}): PurchasesToDeliverData {
  return { id: PROJECT_ID, stockState: 'known', purchaseCount: rows.length, rows, ...overrides }
}

async function mountDeliveries(read: PurchasesToDeliverData, courierTransportState: 'in_transit' | null = null): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (path: string) => (path === DELIVERIES_PATH ? read : undefined))
  setActivePinia(createPinia())
  if (courierTransportState !== null) {
    useGroupCourierStore().courier = readyCourier(PROJECT_ID, {
      lines: [{ bomLineId: 'line-tritanium', typeId: TRITANIUM_TYPE_ID, purchasedQuantity: 600, transportState: courierTransportState }],
    })
  }
  const wrapper = mount(ProjectPurchasesToDeliver, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function shown(wrapper: VueWrapper, testId: string): string {
  const found = wrapper.find(`[data-testid="${testId}"]`)
  return found.exists() ? found.text().replace(/\s+/g, ' ') : 'absent'
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ProjectPurchasesToDeliver', () => {
  it('lists a purchase made outside Jita under its system with the bought and the missing quantity', async () => {
    const wrapper = await mountDeliveries(deliveries([row({ missingQuantity: 1000, deliveryState: 'missing' })]))

    expect(apiRequest).toHaveBeenCalledWith(DELIVERIES_PATH)
    expect(shown(wrapper, 'deliveries-group-other-30001001')).toContain('Bought in C-J6MT')
    const line = wrapper.find('[data-testid="deliveries-row-34-other-30001001"]')
    expect(line.attributes('data-state')).toBe('missing')
    expect(line.text()).toContain('Tritanium')
    expect(shown(wrapper, 'deliveries-missing')).toBe('1,000')
    expect(shown(wrapper, 'deliveries-delivery-state')).toBe('missing')
  })

  it('shows the remaining quantity of a partial delivery', async () => {
    const wrapper = await mountDeliveries(deliveries([row({})]))

    expect(shown(wrapper, 'deliveries-missing')).toBe('600')
    expect(shown(wrapper, 'deliveries-delivery-state')).toBe('partial')
  })

  it('shows an unknown delivery as an unknown value with the stock notice, never as zero', async () => {
    const wrapper = await mountDeliveries(deliveries([row({ missingQuantity: null, deliveryState: 'unknown' })], { stockState: 'unknown' }))

    expect(shown(wrapper, 'deliveries-missing')).toBe('—')
    expect(shown(wrapper, 'deliveries-delivery-state')).toBe('unknown')
    expect(wrapper.find('[data-testid="deliveries-missing"] [title]').exists()).toBe(true)
    expect(shown(wrapper, 'deliveries-stock-notice')).toContain('cannot be read')
  })

  it('explains that a project without container name cannot be checked', async () => {
    const wrapper = await mountDeliveries(deliveries([row({ missingQuantity: null, deliveryState: 'unknown' })], { stockState: 'none' }))

    expect(wrapper.find('[data-testid="deliveries-stock-notice"]').attributes('data-stock-state')).toBe('none')
    expect(shown(wrapper, 'deliveries-stock-notice')).toContain('no container name')
  })

  it('groups the rows by origin: Jita, another system, unknown origin', async () => {
    const wrapper = await mountDeliveries(deliveries([
      row({ originKind: 'jita', solarSystemId: 30000142, solarSystemName: 'Jita' }),
      row({ typeId: NOCXIUM_TYPE_ID, typeName: 'Nocxium', bomLineId: 'line-nocxium' }),
      row({ typeId: NOCXIUM_TYPE_ID, typeName: 'Nocxium', bomLineId: 'line-nocxium', originKind: 'unknown', solarSystemId: null, solarSystemName: null }),
    ]))

    const groupTitles = wrapper.findAll('[data-testid^="deliveries-group-"] h3').map(title => title.text())
    expect(groupTitles).toEqual(['Bought in Jita', 'Bought in C-J6MT', 'Unknown origin'])
  })

  it('keeps the courier state next to the delivery state of a Jita line only', async () => {
    const wrapper = await mountDeliveries(deliveries([
      row({ originKind: 'jita', solarSystemId: 30000142, solarSystemName: 'Jita' }),
      row({ typeId: NOCXIUM_TYPE_ID, typeName: 'Nocxium', bomLineId: 'line-nocxium' }),
    ]), 'in_transit')

    const jitaLine = wrapper.find('[data-testid="deliveries-row-34-jita"]')
    expect(jitaLine.find('[data-testid="deliveries-courier-state"]').text()).toBe('in transit')
    expect(jitaLine.find('[data-testid="deliveries-delivery-state"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="deliveries-row-38-other-30001001"] [data-testid="deliveries-courier-state"]').exists()).toBe(false)
  })

  it('omits the courier state of a Jita line the courier does not carry', async () => {
    const wrapper = await mountDeliveries(deliveries([row({ originKind: 'jita', solarSystemId: 30000142, solarSystemName: 'Jita', bomLineId: 'line-other' })]), 'in_transit')

    expect(wrapper.find('[data-testid="deliveries-courier-state"]').exists()).toBe(false)
  })

  it('truncates a long type name and system name with a tooltip', async () => {
    const wrapper = await mountDeliveries(deliveries([row({ typeName: LONG_SYSTEM_NAME, solarSystemName: LONG_SYSTEM_NAME })]))

    const name = wrapper.find('[data-testid="deliveries-row-34-other-30001001"] p.truncate')
    expect(name.attributes('title')).toBe(LONG_SYSTEM_NAME)
  })

  it('says every purchase is delivered when purchases exist but none is left to bring', async () => {
    const wrapper = await mountDeliveries(deliveries([], { purchaseCount: 3 }))

    expect(shown(wrapper, 'deliveries-all-delivered')).toContain('already in the project container')
  })

  it('stays hidden when the project has no purchase', async () => {
    const wrapper = await mountDeliveries(deliveries([], { purchaseCount: 0 }))

    expect(wrapper.find('[data-testid="deliveries-card"]').exists()).toBe(false)
  })

  it('shows the read error', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('boom'))
    setActivePinia(createPinia())
    const wrapper = mount(ProjectPurchasesToDeliver, { props: { projectId: PROJECT_ID }, global: { plugins: [i18n] } })
    await flushPromises()

    expect(shown(wrapper, 'deliveries-error')).toContain('boom')
  })
})
