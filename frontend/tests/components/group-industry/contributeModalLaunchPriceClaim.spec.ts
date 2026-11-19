import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContributeModal from '@/components/group-industry/ContributeModal.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem } from '@/stores/group-industry/types'
import { present } from '../../present'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-1050'
const LAUNCH_PRICE_SOURCE = '[data-testid="value-source-launch_price"]'
const contributeLabels = en.groupIndustry.contribute

function bomLine(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'bom-tritanium',
    typeId: 34,
    typeName: 'Tritanium',
    requiredQuantity: 100,
    fulfilledQuantity: 0,
    remainingQuantity: 100,
    fulfillmentPercent: 0,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: false,
    jobGroup: null,
    activityType: null,
    parentTypeId: null,
    meLevel: null,
    teLevel: null,
    runs: null,
    isFulfilled: false,
    jobMatches: [],
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    pendingContributions: [],
    reservedQuantity: 0,
    freeQuantity: 0,
    ...overrides,
  }
}

async function openModal(line: BomItem): Promise<VueWrapper> {
  useGroupProjectStore().bomItems = [line]
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(ContributeModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: false, projectId: PROJECT_ID, prefilledBomItem: line },
  })
  await wrapper.setProps({ show: true })
  await flushPromises()
  return wrapper
}

function submittedBody(): Record<string, unknown> {
  const submissions = vi.mocked(apiRequest).mock.calls.filter(([, options]) => options?.method === 'POST')
  expect(submissions).toHaveLength(1)
  return JSON.parse(String(present(submissions[0])[1]?.body))
}

async function submit(wrapper: VueWrapper): Promise<void> {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === contributeLabels.submit)
  await present(button).trigger('click')
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue({} as never)
})

describe('ContributeModal claim of the unclaimed stock at the launch price', () => {
  it('offers the launch price source on a line with unclaimed stock and a frozen launch price', async () => {
    const wrapper = await openModal(bomLine({ unclaimedQuantity: 80, launchPrice: 12.5, unclaimedValue: 1000 }))

    expect(wrapper.find(LAUNCH_PRICE_SOURCE).exists()).toBe(true)
    expect(wrapper.text()).toContain(contributeLabels.valueSource.launchPrice)
  })

  it.each([
    ['no launch price is frozen', { unclaimedQuantity: 80, launchPrice: null, unclaimedValue: null }],
    ['nothing is unclaimed', { unclaimedQuantity: 0, launchPrice: 12.5, unclaimedValue: 0 }],
    ['the unclaimed stock is unknown', { unclaimedQuantity: null, launchPrice: 12.5, unclaimedValue: null }],
  ])('does not offer the launch price source when %s', async (_reason, overrides) => {
    const wrapper = await openModal(bomLine(overrides))

    expect(wrapper.find(LAUNCH_PRICE_SOURCE).exists()).toBe(false)
  })

  it('keeps the Jita price and wallet purchase sources next to the launch price', async () => {
    const wrapper = await openModal(bomLine({ unclaimedQuantity: 80, launchPrice: 12.5, unclaimedValue: 1000 }))

    expect([
      wrapper.find('[data-testid="value-source-jita_price"]').exists(),
      wrapper.find('[data-testid="value-source-wallet_purchase"]').exists(),
    ]).toEqual([true, true])
  })

  it('sends the launch price source with the contribution', async () => {
    const line = bomLine({ unclaimedQuantity: 80, launchPrice: 12.5, unclaimedValue: 1000 })
    const wrapper = await openModal(line)

    await wrapper.find(LAUNCH_PRICE_SOURCE).setValue(true)
    await submit(wrapper)

    expect(submittedBody()).toMatchObject({ type: 'material', bomItemId: line.id, quantity: 100, valueSource: 'launch_price' })
  })
})
