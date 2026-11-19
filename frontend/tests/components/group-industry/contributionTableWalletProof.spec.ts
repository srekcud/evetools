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

const PROJECT_ID = 'project-1092'

type WalletContribution = GroupContribution & { walletBackedQuantity?: number | null }

function contribution(overrides: Partial<WalletContribution>): WalletContribution {
  return {
    id: 'contribution',
    memberCharacterName: 'Aria Solberg',
    memberId: 'member-aria',
    bomItemId: 'bom-line',
    bomItemTypeName: 'Tritanium',
    type: 'material',
    quantity: 100,
    estimatedValue: 700,
    status: 'pending',
    isAutoDetected: true,
    isVerified: false,
    valuedAtJitaPrice: false,
    reopenable: false,
    duplicatesDerived: false,
    reviewedByCharacterName: null,
    reviewedAt: null,
    note: null,
    createdAt: '2026-11-19T09:00:00+00:00',
    ...overrides,
  }
}

const FULLY_BACKED = contribution({ id: 'full', bomItemTypeName: 'Tritanium', walletBackedQuantity: 100 })
const PARTLY_BACKED = contribution({ id: 'partial', bomItemTypeName: 'Pyerite', walletBackedQuantity: 60 })
const NOT_BACKED = contribution({ id: 'none', bomItemTypeName: 'Mexallon', walletBackedQuantity: 0, isAutoDetected: false })
const JOB_INSTALL = contribution({ id: 'job', type: 'job_install', bomItemTypeName: 'Sabre', quantity: 10, walletBackedQuantity: null })

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

function rowNames(wrapper: VueWrapper): string[] {
  return wrapper.findAll('tbody tr').map(row => row.text())
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ContributionTable wallet proof of material contributions', () => {
  it('shows Wallet ✓ when the whole quantity is backed by wallet transactions', async () => {
    const wrapper = await mountTable([FULLY_BACKED])

    expect(rowOf(wrapper, 'Tritanium').text()).toContain('Wallet ✓')
  })

  it('shows Wallet x / y when the quantity is partly backed', async () => {
    const wrapper = await mountTable([PARTLY_BACKED])

    expect(rowOf(wrapper, 'Pyerite').text()).toContain('Wallet 60 / 100')
  })

  it('shows No wallet proof when nothing is backed, on a hand declaration too', async () => {
    const wrapper = await mountTable([NOT_BACKED])

    expect(rowOf(wrapper, 'Mexallon').text()).toContain('No wallet proof')
  })

  it('shows no wallet badge on a job install contribution', async () => {
    const wrapper = await mountTable([JOB_INSTALL])

    expect(rowOf(wrapper, 'Sabre').text()).not.toMatch(/Wallet|wallet proof/)
  })

  it('translates the wallet badges and the formerly hardcoded labels in French', async () => {
    const wrapper = await mountTable([FULLY_BACKED, NOT_BACKED], 'fr')
    const text = wrapper.text()

    expect(text).toContain('Wallet ✓')
    expect(text).toContain('Aucune preuve wallet')
    expect(text).not.toContain('Unverified')
    expect(text).not.toContain('Auto-detected')
  })

  it('keeps the English labels Auto-detected and Unverified next to the wallet badge', async () => {
    const wrapper = await mountTable([FULLY_BACKED])
    const text = rowOf(wrapper, 'Tritanium').text()

    expect(text).toContain('Auto-detected')
    expect(text).toContain('Unverified')
  })

  it('keeps only the rows without wallet proof when the filter says so', async () => {
    const wrapper = await mountTable([FULLY_BACKED, PARTLY_BACKED, NOT_BACKED, JOB_INSTALL])

    await wrapper.get('[data-testid="wallet-proof-filter"]').setValue('none')

    const rows = rowNames(wrapper)
    expect(rows).toHaveLength(1)
    expect(rows[0]).toContain('Mexallon')
  })

  it('keeps the fully and partly backed material rows when the filter asks for wallet proof', async () => {
    const wrapper = await mountTable([FULLY_BACKED, PARTLY_BACKED, NOT_BACKED, JOB_INSTALL])

    await wrapper.get('[data-testid="wallet-proof-filter"]').setValue('backed')

    const rows = rowNames(wrapper).join('|')
    expect(rows).toContain('Tritanium')
    expect(rows).toContain('Pyerite')
    expect(rows).not.toContain('Mexallon')
    expect(rows).not.toContain('Sabre')
  })
})
