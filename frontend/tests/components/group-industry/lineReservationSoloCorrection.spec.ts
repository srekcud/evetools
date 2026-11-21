import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import LineReservation from '@/components/group-industry/LineReservation.vue'
import { apiRequest } from '@/services/api'
import { useGroupContributionStore } from '@/stores/group-industry/contribution'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupContribution, GroupProject } from '@/stores/group-industry/types'
import { present } from '../../present'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-1159'
const LINE_ID = 'bom-nocxium'
const CONTRIBUTION_ID = 'contribution-1159'
const SUPPLIED_QUANTITY = 40
const CONTRIBUTION_URL = `/industry/projects/${PROJECT_ID}/contributions/${CONTRIBUTION_ID}`

function line(overrides: Partial<BomItem> = {}): BomItem {
  return {
    id: LINE_ID,
    typeId: 38,
    typeName: 'Nocxium',
    requiredQuantity: 100,
    fulfilledQuantity: 100,
    remainingQuantity: 0,
    fulfillmentPercent: 100,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: false,
    jobGroup: null,
    activityType: null,
    parentTypeId: null,
    meLevel: null,
    teLevel: null,
    runs: null,
    isFulfilled: true,
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

function contribution(overrides: Partial<GroupContribution> = {}): GroupContribution {
  return {
    id: CONTRIBUTION_ID,
    memberId: 'member-1',
    memberCharacterName: 'Aria Solberg',
    bomItemId: LINE_ID,
    bomItemTypeName: 'Nocxium',
    type: 'material',
    quantity: SUPPLIED_QUANTITY,
    estimatedValue: 240,
    status: 'approved',
    isAutoDetected: false,
    duplicatesDerived: false,
    isVerified: false,
    valuedAtJitaPrice: true,
    reopenable: true,
    editableByViewer: true,
    reviewableByViewer: true,
    reviewedByCharacterName: 'Aria Solberg',
    reviewedAt: null,
    note: null,
    createdAt: '2026-11-21T10:00:00+00:00',
    ...overrides,
  } as GroupContribution
}

function mountLine(item: BomItem, contributions: GroupContribution[], solo = true): VueWrapper {
  setActivePinia(createPinia())
  useGroupProjectStore().currentProject = { id: PROJECT_ID, name: 'Ishtar batch', status: 'in_progress', solo } as GroupProject
  useGroupContributionStore().contributions = contributions
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

  return mount(LineReservation, { props: { item }, global: { plugins: [i18n] } })
}

function buttonLabelled(wrapper: VueWrapper, label: string) {
  return wrapper.findAll('button').find(button => button.text() === label)
}

describe('LineReservation, correcting what was supplied on a solo project', () => {
  beforeEach(() => {
    vi.mocked(apiRequest).mockReset()
    vi.mocked(apiRequest).mockResolvedValue([])
  })

  it('offers to correct the supplied quantity even when the line is fulfilled', () => {
    const wrapper = mountLine(line(), [contribution()])

    expect(wrapper.find('[data-testid="supplied-contribution"]').text()).toContain('40')
    expect(buttonLabelled(wrapper, 'Correct')).toBeDefined()
    expect(buttonLabelled(wrapper, 'I supplied it')).toBeUndefined()
  })

  it('keeps the I supplied it action on a line that is still open', () => {
    const wrapper = mountLine(line({ isFulfilled: false, remainingQuantity: 60, freeQuantity: 60 }), [contribution()])

    expect(buttonLabelled(wrapper, 'I supplied it')).toBeDefined()
    expect(buttonLabelled(wrapper, 'Correct')).toBeDefined()
  })

  it('offers nothing to correct on a project worked on by a team', () => {
    const wrapper = mountLine(line(), [contribution()], false)

    expect(buttonLabelled(wrapper, 'Correct')).toBeUndefined()
    expect(wrapper.find('[data-testid="supplied-contribution"]').exists()).toBe(false)
  })

  it('ignores contributions that are locked, from another line or not material', () => {
    const wrapper = mountLine(line(), [
      contribution({ id: 'locked', editableByViewer: false }),
      contribution({ id: 'other-line', bomItemId: 'bom-other' }),
      contribution({ id: 'job-install', type: 'job_install' }),
    ])

    expect(buttonLabelled(wrapper, 'Correct')).toBeUndefined()
  })

  it('saves the corrected quantity and reloads the BOM', async () => {
    const wrapper = mountLine(line(), [contribution()])

    await present(buttonLabelled(wrapper, 'Correct')).trigger('click')
    await wrapper.find('input[aria-label="Corrected quantity"]').setValue(25)
    await present(buttonLabelled(wrapper, 'Save')).trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(`${CONTRIBUTION_URL}/details`, { method: 'PATCH', body: JSON.stringify({ quantity: 25 }) })
    expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(`/industry/projects/${PROJECT_ID}/bom`)
    expect(wrapper.find('input[aria-label="Corrected quantity"]').exists()).toBe(false)
  })

  it('sends nothing when the quantity is left unchanged', async () => {
    const wrapper = mountLine(line(), [contribution()])

    await present(buttonLabelled(wrapper, 'Correct')).trigger('click')
    await present(buttonLabelled(wrapper, 'Save')).trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest)).not.toHaveBeenCalled()
    expect(wrapper.find('input[aria-label="Corrected quantity"]').exists()).toBe(false)
  })

  it('caps the corrected quantity at the supplied quantity plus the room left on the line', async () => {
    const wrapper = mountLine(line({ isFulfilled: false, remainingQuantity: 60, freeQuantity: 60 }), [contribution()])

    await present(buttonLabelled(wrapper, 'Correct')).trigger('click')

    expect(wrapper.find('input[aria-label="Corrected quantity"]').attributes('max')).toBe('100')
  })

  it('removes the contribution only after a confirmation', async () => {
    const wrapper = mountLine(line(), [contribution()])

    await present(buttonLabelled(wrapper, 'Correct')).trigger('click')
    await present(buttonLabelled(wrapper, 'Remove')).trigger('click')

    expect(vi.mocked(apiRequest)).not.toHaveBeenCalled()

    await present(buttonLabelled(wrapper, 'Confirm removal')).trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(CONTRIBUTION_URL, { method: 'DELETE' })
    expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(`/industry/projects/${PROJECT_ID}/bom`)
  })

  it('closes the editor without any call on cancel', async () => {
    const wrapper = mountLine(line(), [contribution()])

    await present(buttonLabelled(wrapper, 'Correct')).trigger('click')
    await present(buttonLabelled(wrapper, 'Cancel')).trigger('click')

    expect(vi.mocked(apiRequest)).not.toHaveBeenCalled()
    expect(wrapper.find('input[aria-label="Corrected quantity"]').exists()).toBe(false)
  })

  it('shows an alert and keeps the editor open when the correction is refused', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('The contribution exceeds the remaining quantity of the line'))
    const wrapper = mountLine(line(), [contribution()])

    await present(buttonLabelled(wrapper, 'Correct')).trigger('click')
    await wrapper.find('input[aria-label="Corrected quantity"]').setValue(25)
    await present(buttonLabelled(wrapper, 'Save')).trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.find('input[aria-label="Corrected quantity"]').exists()).toBe(true)
  })
})
