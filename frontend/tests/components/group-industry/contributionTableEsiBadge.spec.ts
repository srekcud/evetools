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

const PROJECT_ID = 'project-1101'
const ESI_BADGE = 'ESI ✓'
const AUTO_DETECTED_BADGE = 'Auto-detected'

function contribution(overrides: Partial<GroupContribution>): GroupContribution {
  return {
    id: 'contribution',
    memberCharacterName: 'Aria Solberg',
    memberId: 'member-aria',
    bomItemId: 'bom-line',
    bomItemTypeName: 'Tritanium',
    type: 'material',
    quantity: 100,
    estimatedValue: 700,
    status: 'approved',
    isAutoDetected: false,
    isVerified: true,
    valuedAtJitaPrice: false,
    valueSource: 'wallet_purchase',
    awaitingQuantity: 0,
    reopenable: false,
    duplicatesDerived: false,
    reviewedByCharacterName: null,
    reviewedAt: null,
    note: null,
    createdAt: '2026-11-19T09:00:00+00:00',
    ...overrides,
  }
}

async function mountTable(contributions: GroupContribution[], locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockResolvedValueOnce(contributions)
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ContributionTable, { global: { plugins: [i18n] }, props: { projectId: PROJECT_ID, isAdmin: true } })
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

describe('ContributionTable ESI badge tells the value origin, not the contribution origin', () => {
  it('shows ESI ✓ and no Auto-detected on a hand declaration valued from the wallet', async () => {
    const wrapper = await mountTable([contribution({ bomItemTypeName: 'Tritanium', isAutoDetected: false, valueSource: 'wallet_purchase' })])
    const text = rowOf(wrapper, 'Tritanium').text()

    expect(text).toContain(ESI_BADGE)
    expect(text).not.toContain(AUTO_DETECTED_BADGE)
  })

  it('shows both badges on a contribution created from a linked wallet buy', async () => {
    const wrapper = await mountTable([contribution({ bomItemTypeName: 'Pyerite', isAutoDetected: true, valueSource: 'wallet_purchase' })])
    const text = rowOf(wrapper, 'Pyerite').text()

    expect(text).toContain(ESI_BADGE)
    expect(text).toContain(AUTO_DETECTED_BADGE)
  })

  it('shows ESI ✓ on an automatic material contribution whose value source is not set', async () => {
    const wrapper = await mountTable([contribution({ bomItemTypeName: 'Isogen', isAutoDetected: true, valueSource: null })])

    expect(rowOf(wrapper, 'Isogen').text()).toContain(ESI_BADGE)
  })

  it('shows no ESI ✓ on a hand declaration valued at the Jita price', async () => {
    const wrapper = await mountTable([
      contribution({ bomItemTypeName: 'Mexallon', isAutoDetected: false, valueSource: 'jita_price', valuedAtJitaPrice: true }),
    ])
    const text = rowOf(wrapper, 'Mexallon').text()

    expect(text).not.toContain(ESI_BADGE)
    expect(text).not.toContain(AUTO_DETECTED_BADGE)
  })

  it('shows no ESI ✓ while a quantity still awaits the wallet', async () => {
    const wrapper = await mountTable([
      contribution({ bomItemTypeName: 'Nocxium', isAutoDetected: false, valueSource: 'wallet_purchase', awaitingQuantity: 40 }),
      contribution({ id: 'auto-awaiting', bomItemTypeName: 'Zydrine', isAutoDetected: true, valueSource: 'wallet_purchase', awaitingQuantity: 10 }),
    ])

    expect(rowOf(wrapper, 'Nocxium').text()).not.toContain(ESI_BADGE)
    expect(rowOf(wrapper, 'Zydrine').text()).not.toContain(ESI_BADGE)
  })

  it('shows both badges on an automatic job install valued at the ESI cost', async () => {
    const wrapper = await mountTable([
      contribution({ bomItemTypeName: 'Sabre', type: 'job_install', isAutoDetected: true, valueSource: null, awaitingQuantity: 0 }),
    ])
    const text = rowOf(wrapper, 'Sabre').text()

    expect(text).toContain(ESI_BADGE)
    expect(text).toContain(AUTO_DETECTED_BADGE)
  })

  it('shows no ESI ✓ on a hand-entered job install', async () => {
    const wrapper = await mountTable([
      contribution({ bomItemTypeName: 'Rifter', type: 'job_install', isAutoDetected: false, valueSource: null }),
    ])

    expect(rowOf(wrapper, 'Rifter').text()).not.toContain(ESI_BADGE)
  })

  it('keeps the wallet proof badge next to the ESI ✓ badge in French', async () => {
    const wrapper = await mountTable(
      [contribution({ bomItemTypeName: 'Tritanium', isAutoDetected: false, valueSource: 'wallet_purchase', walletBackedQuantity: 100 })],
      'fr',
    )
    const text = rowOf(wrapper, 'Tritanium').text()

    expect(text).toContain(ESI_BADGE)
    expect(text).toContain('Wallet ✓')
  })
})
