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
const CONFIRMED_BY_HAND = 'Delivery confirmed by hand'

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
    status: 'pending',
    isAutoDetected: true,
    isVerified: false,
    valuedAtJitaPrice: false,
    reopenable: false,
    duplicatesDerived: false,
    reviewedByCharacterName: null,
    reviewedAt: null,
    note: null,
    createdAt: '2026-11-13T09:00:00+00:00',
    ...overrides,
  }
}

const PENDING_PROPOSAL = contribution({ id: 'proposal', bomItemTypeName: 'Pyerite' })
const PENDING_DEPOSIT = contribution({ id: 'deposit', bomItemTypeName: 'Isogen', isAutoDetected: false })
const CONFIRMED_BY_CONTAINER = contribution({ id: 'by-container', bomItemTypeName: 'Mexallon', status: 'approved', isVerified: true })
const CONFIRMED_MANUALLY = contribution({ id: 'by-hand', bomItemTypeName: 'Nocxium', status: 'approved', isVerified: false, reviewedByCharacterName: 'Kira Doran', reviewedAt: '2026-11-13T10:00:00+00:00' })
const APPROVED_DEPOSIT = contribution({ id: 'approved-deposit', bomItemTypeName: 'Zydrine', isAutoDetected: false, status: 'approved', isVerified: false, reviewedByCharacterName: 'Kira Doran', reviewedAt: '2026-11-13T10:00:00+00:00' })

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

function buttonLabels(row: DOMWrapper<Element>): string[] {
  return row.findAll('button').map(button => button.text())
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ContributionTable confirmation of a delivery', () => {
  it('reads Confirm delivery on a pending proposal and keeps Approve on a pending deposit', async () => {
    const wrapper = await mountTable([PENDING_PROPOSAL, PENDING_DEPOSIT], true)

    expect(buttonLabels(rowOf(wrapper, 'Pyerite'))).toEqual(['Confirm delivery', 'Reject'])
    expect(buttonLabels(rowOf(wrapper, 'Isogen'))).toEqual(['Approve', 'Reject'])
  })

  it('approves the proposal through the review endpoint when the admin confirms the delivery', async () => {
    const wrapper = await mountTable([PENDING_PROPOSAL], true)
    vi.mocked(apiRequest).mockResolvedValueOnce({ ...PENDING_PROPOSAL, status: 'approved' })

    await rowOf(wrapper, 'Pyerite').findAll('button')[0].trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenLastCalledWith(
      `/industry/projects/${PROJECT_ID}/contributions/proposal`,
      { method: 'PATCH', body: JSON.stringify({ status: 'approved' }) },
    )
  })

  it('flags a derived contribution confirmed by hand and none confirmed by the container or declared', async () => {
    const wrapper = await mountTable([CONFIRMED_BY_CONTAINER, CONFIRMED_MANUALLY, APPROVED_DEPOSIT], false)

    expect(rowOf(wrapper, 'Nocxium').text()).toContain(CONFIRMED_BY_HAND)
    expect(rowOf(wrapper, 'Mexallon').text()).not.toContain(CONFIRMED_BY_HAND)
    expect(rowOf(wrapper, 'Zydrine').text()).not.toContain(CONFIRMED_BY_HAND)
  })
})
