import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContributionTable from '@/components/group-industry/ContributionTable.vue'
import { groupProjectRefreshes } from '@/views/GroupIndustry/groupProjectRefreshes'
import { apiRequest } from '@/services/api'
import type { GroupContribution } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-1012'

function contribution(overrides: Partial<GroupContribution>): GroupContribution {
  return {
    id: 'contribution',
    memberCharacterName: 'Brann Holt',
    memberId: 'member-brann',
    bomItemId: 'bom-line',
    bomItemTypeName: 'Tritanium',
    type: 'material',
    quantity: 100,
    estimatedValue: 500,
    status: 'pending',
    isAutoDetected: false,
    isVerified: false,
    valuedAtJitaPrice: true,
    reopenable: false,
    duplicatesDerived: false,
    reviewedByCharacterName: null,
    reviewedAt: null,
    note: 'original note',
    createdAt: '2026-11-16T09:00:00+00:00',
    editableByViewer: false,
    ...overrides,
  }
}

const OWN_MATERIAL = contribution({ id: 'own-material', bomItemTypeName: 'Tritanium', editableByViewer: true })
const OWN_JOB_INSTALL = contribution({ id: 'own-job', bomItemTypeName: 'Ishtar', type: 'job_install', quantity: 5, estimatedValue: 9000, editableByViewer: true })
const SOMEONE_ELSES = contribution({ id: 'other', bomItemTypeName: 'Pyerite', memberCharacterName: 'Aria Solberg' })
const OWN_APPROVED = contribution({ id: 'own-approved', bomItemTypeName: 'Mexallon', status: 'approved', editableByViewer: false })

async function mountTable(contributions: GroupContribution[], isAdmin = false, locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
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

describe('ContributionTable edit and withdrawal of one own pending contribution', () => {
  it('offers Edit and Withdraw on the viewer own editable contributions only', async () => {
    const wrapper = await mountTable([OWN_MATERIAL, SOMEONE_ELSES, OWN_APPROVED])

    expect(buttonLabels(rowOf(wrapper, 'Tritanium'))).toEqual(['Edit', 'Withdraw'])
    expect(buttonLabels(rowOf(wrapper, 'Pyerite'))).toEqual([])
    expect(buttonLabels(rowOf(wrapper, 'Mexallon'))).toEqual([])
  })

  it('offers an admin Approve and Reject on a participant contribution and no Edit or Withdraw', async () => {
    const wrapper = await mountTable([SOMEONE_ELSES], true)

    expect(buttonLabels(rowOf(wrapper, 'Pyerite'))).toEqual(['Approve', 'Reject'])
  })

  it('translates the actions in French', async () => {
    const wrapper = await mountTable([OWN_MATERIAL], false, 'fr')

    expect(buttonLabels(rowOf(wrapper, 'Tritanium'))).toEqual(['Modifier', 'Retirer'])
  })

  it('asks for a confirmation before withdrawing and deletes the contribution on confirm', async () => {
    const wrapper = await mountTable([OWN_MATERIAL, SOMEONE_ELSES])

    await rowOf(wrapper, 'Tritanium').findAll('button')[1].trigger('click')

    expect(apiRequest).toHaveBeenCalledTimes(1)
    expect(buttonLabels(rowOf(wrapper, 'Tritanium'))).toEqual(['Confirm withdrawal', 'Cancel'])

    vi.mocked(apiRequest).mockResolvedValueOnce(undefined)
    await rowOf(wrapper, 'Tritanium').findAll('button')[0].trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenLastCalledWith(`/industry/projects/${PROJECT_ID}/contributions/own-material`, { method: 'DELETE' })
    expect(wrapper.text()).not.toContain('Tritanium')
    expect(wrapper.text()).toContain('Pyerite')
  })

  it('keeps the contribution when the withdrawal is cancelled', async () => {
    const wrapper = await mountTable([OWN_MATERIAL])

    await rowOf(wrapper, 'Tritanium').findAll('button')[1].trigger('click')
    await rowOf(wrapper, 'Tritanium').findAll('button')[1].trigger('click')

    expect(apiRequest).toHaveBeenCalledTimes(1)
    expect(buttonLabels(rowOf(wrapper, 'Tritanium'))).toEqual(['Edit', 'Withdraw'])
  })

  it('edits the quantity and the note of a material contribution without sending a value', async () => {
    const wrapper = await mountTable([OWN_MATERIAL])

    await rowOf(wrapper, 'Tritanium').findAll('button')[0].trigger('click')
    const row = rowOf(wrapper, 'Tritanium')
    expect(row.find('[data-testid="edit-value"]').exists()).toBe(false)
    expect((row.find('[data-testid="edit-quantity"]').element as HTMLInputElement).value).toBe('100')
    await row.find('[data-testid="edit-quantity"]').setValue('150')
    await row.find('[data-testid="edit-note"]').setValue('bought it')

    vi.mocked(apiRequest).mockResolvedValueOnce({ ...OWN_MATERIAL, quantity: 150, estimatedValue: 900, note: 'bought it' })
    await buttonLabelsClick(rowOf(wrapper, 'Tritanium'), 'Save')
    await flushPromises()

    expect(apiRequest).toHaveBeenLastCalledWith(
      `/industry/projects/${PROJECT_ID}/contributions/own-material/details`,
      { method: 'PATCH', body: JSON.stringify({ quantity: 150, note: 'bought it' }) },
    )
    expect(rowOf(wrapper, 'Tritanium').text()).toContain('150')
    expect(rowOf(wrapper, 'Tritanium').find('[data-testid="edit-quantity"]').exists()).toBe(false)
  })

  it('sends only the changed fields so a note edit does not revalue the contribution', async () => {
    const wrapper = await mountTable([OWN_MATERIAL])
    await rowOf(wrapper, 'Tritanium').findAll('button')[0].trigger('click')
    await rowOf(wrapper, 'Tritanium').find('[data-testid="edit-note"]').setValue('only the note')

    vi.mocked(apiRequest).mockResolvedValueOnce({ ...OWN_MATERIAL, note: 'only the note' })
    await buttonLabelsClick(rowOf(wrapper, 'Tritanium'), 'Save')
    await flushPromises()

    expect(apiRequest).toHaveBeenLastCalledWith(
      `/industry/projects/${PROJECT_ID}/contributions/own-material/details`,
      { method: 'PATCH', body: JSON.stringify({ note: 'only the note' }) },
    )
  })

  it('edits the entered value of a job install contribution', async () => {
    const wrapper = await mountTable([OWN_JOB_INSTALL])

    await rowOf(wrapper, 'Ishtar').findAll('button')[0].trigger('click')
    await rowOf(wrapper, 'Ishtar').find('[data-testid="edit-value"]').setValue('12000')

    vi.mocked(apiRequest).mockResolvedValueOnce({ ...OWN_JOB_INSTALL, estimatedValue: 12000 })
    await buttonLabelsClick(rowOf(wrapper, 'Ishtar'), 'Save')
    await flushPromises()

    expect(apiRequest).toHaveBeenLastCalledWith(
      `/industry/projects/${PROJECT_ID}/contributions/own-job/details`,
      { method: 'PATCH', body: JSON.stringify({ estimatedValue: 12000 }) },
    )
  })

  it('shows the refusal of the server and keeps the edit open', async () => {
    const wrapper = await mountTable([OWN_MATERIAL])
    await rowOf(wrapper, 'Tritanium').findAll('button')[0].trigger('click')
    await rowOf(wrapper, 'Tritanium').find('[data-testid="edit-quantity"]').setValue('10000')

    vi.mocked(apiRequest).mockRejectedValueOnce(new Error('The contribution exceeds the remaining quantity of the line'))
    await buttonLabelsClick(rowOf(wrapper, 'Tritanium'), 'Save')
    await flushPromises()

    expect(wrapper.text()).toContain('The contribution exceeds the remaining quantity of the line')
    expect(rowOf(wrapper, 'Tritanium').find('[data-testid="edit-quantity"]').exists()).toBe(true)
  })
})

describe('group project refreshes for the contributor actions', () => {
  it('refreshes the contributions, the BOM and the cockpit when a contribution is withdrawn or updated', () => {
    for (const action of ['contribution_withdrawn', 'contribution_updated']) {
      expect(groupProjectRefreshes(action)).toEqual(expect.arrayContaining(['contributions', 'bom', 'cockpit', 'projectDetail']))
    }
  })
})

async function buttonLabelsClick(row: DOMWrapper<Element>, label: string): Promise<void> {
  const button = row.findAll('button').find(candidate => candidate.text() === label)
  if (button === undefined) throw new Error(`button ${label} not found`)
  await button.trigger('click')
}
