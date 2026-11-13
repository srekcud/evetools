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

const PROJECT_ID = 'project-510'
const DUPLICATE_LABEL_KEY = 'groupIndustry.contributions.duplicatesDerived'
const FRENCH_DUPLICATE_LABEL = 'Doublon avec un apport ESI'

function contribution(overrides: Partial<GroupContribution>): GroupContribution {
  return {
    id: 'contribution',
    memberCharacterName: 'Aria Solberg',
    memberId: 'member-aria',
    bomItemId: 'bom-line',
    bomItemTypeName: 'Tritanium',
    type: 'material',
    quantity: 400,
    estimatedValue: 2_800,
    status: 'approved',
    isAutoDetected: false,
    isVerified: false,
    valuedAtJitaPrice: false,
    reopenable: false,
    duplicatesDerived: false,
    reviewedByCharacterName: 'Kira Doran',
    reviewedAt: '2026-11-09T10:00:00+00:00',
    note: null,
    createdAt: '2026-11-09T09:00:00+00:00',
    ...overrides,
  }
}

const DUPLICATE_DECLARED = contribution({ id: 'duplicate-declared', bomItemTypeName: 'Tritanium', duplicatesDerived: true })
const DERIVED_TWIN = contribution({ id: 'derived-twin', bomItemTypeName: 'Tritanium', quantity: 600, estimatedValue: 4_200, isAutoDetected: true, isVerified: true })
const APPROVED_WITHOUT_TWIN = contribution({ id: 'approved-alone', bomItemTypeName: 'Pyerite', quantity: 250, estimatedValue: 2_875 })
const PENDING_DECLARED = contribution({ id: 'pending-declared', bomItemTypeName: 'Mexallon', quantity: 330, estimatedValue: 16_087.5, status: 'pending', reviewedByCharacterName: null, reviewedAt: null })

async function mountTable(contributions: GroupContribution[], isAdmin: boolean, locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockResolvedValueOnce(contributions)
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ContributionTable, { global: { plugins: [i18n] }, props: { projectId: PROJECT_ID, isAdmin } })
  await flushPromises()
  return wrapper
}

function rowOf(wrapper: VueWrapper, typeName: string, quantity: number): DOMWrapper<Element> {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.text().includes(typeName) && candidate.text().includes(String(quantity)))
  if (row === undefined) throw new Error(`row of ${typeName} ${quantity} not found`)
  return row
}

function buttonLabels(row: DOMWrapper<Element>): string[] {
  return row.findAll('button').map(button => button.text())
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ContributionTable approved duplicate of a derived contribution', () => {
  it('offers an admin Reject and never Approve on the approved declared contribution that duplicates a derived one', async () => {
    const wrapper = await mountTable([DUPLICATE_DECLARED, DERIVED_TWIN], true)

    expect(buttonLabels(rowOf(wrapper, 'Tritanium', 400))).toEqual(['Reject'])
  })

  it('offers an admin no action on the derived twin, on an approved contribution without twin or on a rejected one', async () => {
    const rejected = contribution({ id: 'rejected-declared', bomItemTypeName: 'Nocxium', quantity: 90, status: 'rejected' })
    const wrapper = await mountTable([DUPLICATE_DECLARED, DERIVED_TWIN, APPROVED_WITHOUT_TWIN, rejected], true)

    expect(buttonLabels(rowOf(wrapper, 'Tritanium', 600))).toEqual([])
    expect(buttonLabels(rowOf(wrapper, 'Pyerite', 250))).toEqual([])
    expect(buttonLabels(rowOf(wrapper, 'Nocxium', 90))).toEqual([])
  })

  it('offers a non-admin no action on the duplicate', async () => {
    const wrapper = await mountTable([DUPLICATE_DECLARED, DERIVED_TWIN], false)

    expect(buttonLabels(rowOf(wrapper, 'Tritanium', 400))).toEqual([])
  })

  it('keeps Approve and Reject on a pending contribution for an admin', async () => {
    const wrapper = await mountTable([PENDING_DECLARED, DUPLICATE_DECLARED, DERIVED_TWIN], true)

    expect(buttonLabels(rowOf(wrapper, 'Mexallon', 330))).toEqual(['Approve', 'Reject'])
  })

  it('shows the duplicate badge in French on the duplicate only', async () => {
    const wrapper = await mountTable([DUPLICATE_DECLARED, DERIVED_TWIN, APPROVED_WITHOUT_TWIN], true, 'fr')

    expect(rowOf(wrapper, 'Tritanium', 400).text()).toContain(FRENCH_DUPLICATE_LABEL)
    expect(rowOf(wrapper, 'Tritanium', 600).text()).not.toContain(FRENCH_DUPLICATE_LABEL)
    expect(rowOf(wrapper, 'Pyerite', 250).text()).not.toContain(FRENCH_DUPLICATE_LABEL)
  })

  it('translates the duplicate badge in English with a text of its own', async () => {
    const wrapper = await mountTable([DUPLICATE_DECLARED, DERIVED_TWIN], false, 'en')
    const english = createI18n({ legacy: false, locale: 'en', messages: { en, fr } }).global.t(DUPLICATE_LABEL_KEY)

    expect(english).not.toBe(DUPLICATE_LABEL_KEY)
    expect(english).not.toBe(FRENCH_DUPLICATE_LABEL)
    expect(rowOf(wrapper, 'Tritanium', 400).text()).toContain(english)
  })

  it('patches the duplicate to rejected when the admin clicks Reject and drops its badge and its buttons', async () => {
    const wrapper = await mountTable([DUPLICATE_DECLARED, DERIVED_TWIN], true, 'fr')
    vi.mocked(apiRequest).mockResolvedValueOnce(contribution({ id: 'duplicate-declared', status: 'rejected', duplicatesDerived: false }))

    await rowOf(wrapper, 'Tritanium', 400).find('button').trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenLastCalledWith(
      `/industry/projects/${PROJECT_ID}/contributions/duplicate-declared`,
      { method: 'PATCH', body: JSON.stringify({ status: 'rejected' }) },
    )
    expect(buttonLabels(rowOf(wrapper, 'Tritanium', 400))).toEqual([])
    expect(rowOf(wrapper, 'Tritanium', 400).text()).not.toContain(FRENCH_DUPLICATE_LABEL)
  })
})
