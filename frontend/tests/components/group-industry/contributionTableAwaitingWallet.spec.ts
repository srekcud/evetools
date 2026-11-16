import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContributionTable from '@/components/group-industry/ContributionTable.vue'
import { apiRequest } from '@/services/api'
import type { GroupContribution } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-1009'
const labels = en.groupIndustry.contributions

function contribution(overrides: Partial<GroupContribution>): GroupContribution {
  return {
    id: 'contribution',
    memberCharacterName: 'Brann Holt',
    memberId: 'member-brann',
    bomItemId: 'bom-line',
    bomItemTypeName: 'Tritanium',
    type: 'material',
    quantity: 100,
    estimatedValue: null,
    status: 'pending',
    isAutoDetected: false,
    isVerified: false,
    valuedAtJitaPrice: false,
    valueSource: 'wallet_purchase',
    awaitingQuantity: 100,
    switchableToJitaPrice: false,
    reopenable: false,
    duplicatesDerived: false,
    reviewedByCharacterName: null,
    reviewedAt: null,
    note: null,
    createdAt: '2026-11-16T09:00:00+00:00',
    ...overrides,
  }
}

const AWAITING_ALL = contribution({ id: 'awaiting-all', bomItemTypeName: 'Tritanium' })
const AWAITING_PART = contribution({ id: 'awaiting-part', bomItemTypeName: 'Pyerite', estimatedValue: 300, awaitingQuantity: 40, switchableToJitaPrice: true })
const FULLY_VALUED = contribution({ id: 'fully-valued', bomItemTypeName: 'Isogen', estimatedValue: 500, awaitingQuantity: 0 })

async function mountTable(contributions: GroupContribution[], isAdmin: boolean): Promise<VueWrapper> {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockResolvedValueOnce(contributions)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(ContributionTable, { global: { plugins: [i18n] }, props: { projectId: PROJECT_ID, isAdmin } })
  await flushPromises()
  return wrapper
}

function rowOf(wrapper: VueWrapper, typeName: string): DOMWrapper<Element> {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.text().includes(typeName))
  if (row === undefined) throw new Error(`row of ${typeName} not found`)
  return row
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ContributionTable contribution awaiting a wallet transaction', () => {
  it('shows the units awaiting a transaction and an unknown value, never zero', async () => {
    const wrapper = await mountTable([AWAITING_ALL], false)

    const row = rowOf(wrapper, 'Tritanium')
    expect(row.text()).toContain('100 units awaiting wallet transaction')
    expect(row.text()).toContain(labels.valueUnknown)
    expect(row.text()).not.toContain('0 ISK')
  })

  it('shows the value of the matched units and the units still awaiting', async () => {
    const wrapper = await mountTable([AWAITING_PART], false)

    expect(rowOf(wrapper, 'Pyerite').text()).toContain('40 units awaiting wallet transaction')
  })

  it('disables the approval of a contribution awaiting units and explains why', async () => {
    const wrapper = await mountTable([AWAITING_PART, FULLY_VALUED], true)

    const approveOfAwaiting = rowOf(wrapper, 'Pyerite').findAll<HTMLButtonElement>('button').find(button => button.text() === 'Approve')
    const approveOfValued = rowOf(wrapper, 'Isogen').findAll<HTMLButtonElement>('button').find(button => button.text() === 'Approve')
    expect(approveOfAwaiting?.element.disabled).toBe(true)
    expect(approveOfAwaiting?.attributes('title')).toBe(labels.approveBlockedAwaiting)
    expect(approveOfValued?.element.disabled).toBe(false)
  })

  it('offers the contributor the Jita price and posts the switch', async () => {
    const wrapper = await mountTable([AWAITING_PART], false)
    vi.mocked(apiRequest).mockResolvedValueOnce({ ...AWAITING_PART, estimatedValue: 580, awaitingQuantity: 0, valueSource: 'jita_price', valuedAtJitaPrice: true, switchableToJitaPrice: false })

    const switchButton = rowOf(wrapper, 'Pyerite').findAll('button').find(button => button.text() === labels.valueAtJitaPrice)
    expect(switchButton, 'switch button').toBeDefined()
    await switchButton?.trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenLastCalledWith(
      `/industry/projects/${PROJECT_ID}/contributions/awaiting-part/value-at-jita-price`,
      { method: 'POST', body: JSON.stringify({}) },
    )
    expect(rowOf(wrapper, 'Pyerite').text()).not.toContain('units awaiting wallet transaction')
  })

  it('offers no switch to someone who is not the contributor', async () => {
    const wrapper = await mountTable([AWAITING_ALL], true)

    expect(rowOf(wrapper, 'Tritanium').findAll('button').map(button => button.text())).not.toContain(labels.valueAtJitaPrice)
  })
})
