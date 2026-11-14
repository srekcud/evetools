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

const PROJECT_ID = 'project-953'

function contribution(overrides: Partial<GroupContribution>): GroupContribution {
  return {
    id: 'contribution',
    memberCharacterName: 'Aria Solberg',
    memberId: 'member-aria',
    bomItemId: 'bom-line',
    bomItemTypeName: 'Tritanium',
    type: 'material',
    quantity: 100,
    estimatedValue: 500,
    status: 'approved',
    isAutoDetected: false,
    isVerified: false,
    valuedAtJitaPrice: false,
    reopenable: true,
    duplicatesDerived: false,
    reviewedByCharacterName: 'Kira Doran',
    reviewedAt: '2026-11-13T10:00:00+00:00',
    note: null,
    createdAt: '2026-11-13T09:00:00+00:00',
    ...overrides,
  }
}

const REOPENABLE_MATERIAL = contribution({ id: 'material-approved', bomItemTypeName: 'Tritanium' })
const APPROVED_JOB_INSTALL = contribution({ id: 'job-approved', bomItemTypeName: 'Ishtar', type: 'job_install', quantity: 5, reopenable: false })
const DERIVED_PROPOSAL = contribution({ id: 'derived-proposal', bomItemTypeName: 'Pyerite', quantity: 250, status: 'pending', isAutoDetected: true, isVerified: false, reopenable: false, reviewedByCharacterName: null, reviewedAt: null })
const DERIVED_VERIFIED = contribution({ id: 'derived-verified', bomItemTypeName: 'Mexallon', quantity: 330, isAutoDetected: true, isVerified: true, reopenable: false })
const PAID_DEPOSIT = contribution({ id: 'paid-deposit', bomItemTypeName: 'Isogen', quantity: 40, status: 'pending', reopenable: false })
const JITA_DEPOSIT = contribution({ id: 'jita-deposit', bomItemTypeName: 'Nocxium', quantity: 60, status: 'pending', valuedAtJitaPrice: true, reopenable: false })

async function mountTable(contributions: GroupContribution[], isAdmin: boolean, locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockResolvedValueOnce(contributions)
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ContributionTable, { global: { plugins: [i18n] }, props: { projectId: PROJECT_ID, isAdmin } })
  await flushPromises()
  return wrapper
}

function rowOf(wrapper: VueWrapper, typeName: string): DOMWrapper<Element> {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.text().includes(typeName))
  if (row === undefined) throw new Error(`row of ${typeName} not found`)
  return row
}

function buttonLabels(row: DOMWrapper<Element>): string[] {
  return row.findAll('button').map(button => button.text())
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ContributionTable reopening of an approved material contribution', () => {
  it('offers an admin Reopen on a reopenable contribution only', async () => {
    const wrapper = await mountTable([REOPENABLE_MATERIAL, APPROVED_JOB_INSTALL, DERIVED_VERIFIED], true)

    expect(buttonLabels(rowOf(wrapper, 'Tritanium'))).toEqual(['Reopen'])
    expect(buttonLabels(rowOf(wrapper, 'Ishtar'))).toEqual([])
    expect(buttonLabels(rowOf(wrapper, 'Mexallon'))).toEqual([])
  })

  it('offers a non-admin no Reopen', async () => {
    const wrapper = await mountTable([REOPENABLE_MATERIAL], false)

    expect(buttonLabels(rowOf(wrapper, 'Tritanium'))).toEqual([])
  })

  it('patches the contribution to pending when the admin clicks Reopen and offers Approve and Reject afterwards', async () => {
    const wrapper = await mountTable([REOPENABLE_MATERIAL], true)
    vi.mocked(apiRequest).mockResolvedValueOnce({ ...REOPENABLE_MATERIAL, status: 'pending', reopenable: false, reviewedByCharacterName: null, reviewedAt: null })

    await rowOf(wrapper, 'Tritanium').findAll('button')[0].trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenLastCalledWith(
      `/industry/projects/${PROJECT_ID}/contributions/material-approved`,
      { method: 'PATCH', body: JSON.stringify({ status: 'pending' }) },
    )
    expect(buttonLabels(rowOf(wrapper, 'Tritanium'))).toEqual(['Approve', 'Reject'])
  })
})

describe('ContributionTable proposals and valuation method', () => {
  it('marks a derived proposal as unverified and a verified derived contribution as not', async () => {
    const wrapper = await mountTable([DERIVED_PROPOSAL, DERIVED_VERIFIED], true)

    expect(rowOf(wrapper, 'Pyerite').text()).toContain('Unverified')
    expect(rowOf(wrapper, 'Mexallon').text()).not.toContain('Unverified')
    expect(buttonLabels(rowOf(wrapper, 'Pyerite'))).toEqual(['Approve', 'Reject'])
  })

  it('reads the paid price for a deposit valued at the paid price and the deposit-day Jita price for one valued at Jita', async () => {
    const wrapper = await mountTable([PAID_DEPOSIT, JITA_DEPOSIT], false, 'fr')

    expect(rowOf(wrapper, 'Isogen').text()).toContain('prix payé (wallet)')
    expect(rowOf(wrapper, 'Isogen').text()).not.toContain("prix Jita du jour d'apport")
    expect(rowOf(wrapper, 'Nocxium').text()).toContain("prix Jita du jour d'apport")
  })
})
