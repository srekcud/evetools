import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectPurchasesToDeliver from '@/components/group-industry/ProjectPurchasesToDeliver.vue'
import { apiRequest } from '@/services/api'
import type { ProjectPurchasesToDeliver as PurchasesToDeliverData, ContainerStockState } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'
const DELIVERIES_PATH = `/industry/projects/${PROJECT_ID}/purchases-to-deliver`
const NAME_CONTAINER_BUTTON = '[data-testid="deliveries-name-container"]'
const STOCK_NOTICE = '[data-testid="deliveries-stock-notice"]'

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

function readOf(stockState: ContainerStockState): PurchasesToDeliverData {
  return {
    id: PROJECT_ID,
    stockState,
    purchaseCount: 1,
    rows: [{
      bomLineId: 'line-tritanium',
      typeId: 34,
      typeName: 'Tritanium',
      originKind: 'jita',
      solarSystemId: 30000142,
      solarSystemName: 'Jita',
      purchasedQuantity: 1000,
      missingQuantity: stockState === 'known' ? 400 : null,
      deliveryState: stockState === 'known' ? 'partial' : 'unknown',
    }],
  }
}

async function mountDeliveries(stockState: ContainerStockState, canEdit: boolean): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async (path: string) => (path === DELIVERIES_PATH ? readOf(stockState) : undefined))
  setActivePinia(createPinia())
  const wrapper = mount(ProjectPurchasesToDeliver, { props: { projectId: PROJECT_ID, canEdit }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ProjectPurchasesToDeliver naming the project container', () => {
  it('offers a button next to the no-container notice that asks to edit the containers', async () => {
    const wrapper = await mountDeliveries('none', true)

    expect(wrapper.find(STOCK_NOTICE).exists()).toBe(true)
    const button = wrapper.find(NAME_CONTAINER_BUTTON)
    expect(button.exists()).toBe(true)
    await button.trigger('click')
    expect(wrapper.emitted('editContainers')).toHaveLength(1)
  })

  it('keeps the notice without the button when the viewer cannot edit the project', async () => {
    const wrapper = await mountDeliveries('none', false)

    expect(wrapper.find(STOCK_NOTICE).exists()).toBe(true)
    expect(wrapper.find(NAME_CONTAINER_BUTTON).exists()).toBe(false)
  })

  it('does not offer to name a container when the stock cannot be read although a container is named', async () => {
    const wrapper = await mountDeliveries('unknown', true)

    expect(wrapper.find(STOCK_NOTICE).exists()).toBe(true)
    expect(wrapper.find(NAME_CONTAINER_BUTTON).exists()).toBe(false)
  })

  it('shows neither notice nor button when the container stock is known', async () => {
    const wrapper = await mountDeliveries('known', true)

    expect(wrapper.find(STOCK_NOTICE).exists()).toBe(false)
    expect(wrapper.find(NAME_CONTAINER_BUTTON).exists()).toBe(false)
  })
})
