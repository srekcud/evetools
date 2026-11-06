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

const PROJECT_ID = 'project-363'

function contribution(overrides: Partial<GroupContribution>): GroupContribution {
  return {
    id: 'contribution',
    memberCharacterName: 'Aria Solberg',
    memberId: 'member-aria',
    bomItemId: 'bom-line',
    bomItemTypeName: 'Tritanium',
    type: 'material',
    quantity: 1,
    estimatedValue: 0,
    status: 'approved',
    isAutoDetected: false,
    isVerified: false,
    reviewedByCharacterName: null,
    reviewedAt: null,
    note: null,
    createdAt: '2026-11-06T10:00:00+00:00',
    ...overrides,
  }
}

const DERIVED_MATERIAL = contribution({ id: 'derived-material', bomItemTypeName: 'Tritanium', quantity: 1_000, estimatedValue: 6_200, isAutoDetected: true, isVerified: true })
const DERIVED_JOB_WITHOUT_COST = contribution({ id: 'derived-job', bomItemTypeName: 'Ishtar', type: 'job_install', quantity: 5, estimatedValue: null, isAutoDetected: true, isVerified: true })
const DEPOSITED_MATERIAL = contribution({ id: 'deposited-material', bomItemTypeName: 'Mexallon', quantity: 330, estimatedValue: 16_087.5, status: 'pending' })
const DECLARED_BPC = contribution({ id: 'declared-bpc', bomItemTypeName: 'Orca', type: 'bpc', quantity: 1, estimatedValue: 36_000, status: 'pending' })

async function mountTable(contributions: GroupContribution[], locale: 'en' | 'fr'): Promise<VueWrapper> {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockResolvedValue(contributions)
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ContributionTable, { global: { plugins: [i18n] }, props: { projectId: PROJECT_ID, isAdmin: false } })
  await flushPromises()
  return wrapper
}

function rowOf(wrapper: VueWrapper, typeName: string): DOMWrapper<Element> {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.text().includes(typeName))
  if (row === undefined) throw new Error(`row of ${typeName} not found`)
  return row
}

function normalizedText(element: VueWrapper | DOMWrapper<Element>): string {
  return element.text().replace(/[\u00a0\u202f]/g, ' ')
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ContributionTable valuation method', () => {
  it('reads the paid price for a derived material, the ESI cost for a derived job, the deposit-day Jita price for a deposited material and the entered value otherwise', async () => {
    const wrapper = await mountTable([DERIVED_MATERIAL, DERIVED_JOB_WITHOUT_COST, DEPOSITED_MATERIAL, DECLARED_BPC], 'fr')

    expect(normalizedText(rowOf(wrapper, 'Tritanium'))).toContain('prix payé (wallet)')
    expect(normalizedText(rowOf(wrapper, 'Ishtar'))).toContain('coût réel (ESI)')
    expect(normalizedText(rowOf(wrapper, 'Mexallon'))).toContain("prix Jita du jour d'apport")
    expect(normalizedText(rowOf(wrapper, 'Orca'))).toContain('valeur saisie')
    expect(wrapper.text()).not.toContain('Jita weighted')
  })
})

describe('ContributionTable contribution without value', () => {
  it('shows an unknown value with its tooltip for a derived job without ESI cost and keeps the known values of the others', async () => {
    const wrapper = await mountTable([DERIVED_MATERIAL, DERIVED_JOB_WITHOUT_COST], 'fr')

    expect(rowOf(wrapper, 'Ishtar').find(`[title="${fr.common.unknownValue}"]`).exists()).toBe(true)
    expect(rowOf(wrapper, 'Tritanium').find(`[title="${fr.common.unknownValue}"]`).exists()).toBe(false)
    expect(wrapper.text()).not.toContain('NaN')
  })

  it('shows the total and the approved value as unknown, never partial, while the pending value stays known', async () => {
    const wrapper = await mountTable([DERIVED_MATERIAL, DERIVED_JOB_WITHOUT_COST, DEPOSITED_MATERIAL], 'en')

    expect(wrapper.findAll(`[title="${en.common.unknownValue}"]`).length).toBeGreaterThanOrEqual(3)
    expect(wrapper.text()).not.toContain('NaN')
  })
})
