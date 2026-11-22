import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import JobLinkModal from '@/components/group-industry/JobLinkModal.vue'
import type { AvailableJob } from '@/stores/group-industry/types'

enableAutoUnmount(afterEach)

const AVAILABLE_JOB = '[data-testid="available-job"]'
const AVAILABLE_JOB_RUNS = '[data-testid="available-job-runs"]'
const AVAILABLE_JOB_SHARED = '[data-testid="available-job-shared"]'
const AVAILABLE_JOB_LINK = '[data-testid="available-job-link"]'
const SHARED_ISHTAR_JOB_ID = 910_001
const SHARED_VEXOR_JOB_ID = 910_002
const FULLY_ASSIGNED_JOB_ID = 910_003

function availableJob(overrides: Partial<AvailableJob>): AvailableJob {
  return {
    esiJobId: 910_000,
    blueprintTypeId: 12006,
    characterName: 'Aria Solberg',
    productTypeId: 12005,
    productTypeName: 'Ishtar',
    startDate: '2026-11-20T08:00:00+00:00',
    endDate: '2026-11-21T08:00:00+00:00',
    runs: 10,
    status: 'active',
    cost: 1_250_000,
    linkedToBomLineId: null,
    linkedToBomLineName: null,
    matchId: null,
    linkedRuns: 0,
    remainingRuns: 10,
    ...overrides,
  } as AvailableJob
}

const SHARED_ISHTAR_JOB = availableJob({ esiJobId: SHARED_ISHTAR_JOB_ID, runs: 10, linkedRuns: 4, remainingRuns: 6 })
const SHARED_VEXOR_JOB = availableJob({
  esiJobId: SHARED_VEXOR_JOB_ID,
  characterName: 'Brann Holt',
  productTypeId: 626,
  productTypeName: 'Vexor',
  runs: 8,
  linkedRuns: 5,
  remainingRuns: 3,
  linkedToBomLineId: 'job-vexor',
  linkedToBomLineName: 'Vexor',
  matchId: 'match-brann',
})
const FULLY_ASSIGNED_JOB = availableJob({
  esiJobId: FULLY_ASSIGNED_JOB_ID,
  characterName: 'Cyra Lune',
  productTypeId: 11567,
  productTypeName: 'Avatar',
  runs: 2,
  linkedRuns: 2,
  remainingRuns: 0,
})
const UNSHARED_JOB = availableJob({ esiJobId: 910_004, characterName: 'Dax Orlan', runs: 7, linkedRuns: 0, remainingRuns: 7 })

function mountModal(jobs: AvailableJob[]): VueWrapper {
  return mount(JobLinkModal, {
    props: { show: true, lineName: 'Ishtar', jobs, loading: false, error: null, linkingEsiJobId: null },
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })], stubs: { teleport: true } },
  })
}

function rowOf(wrapper: VueWrapper, characterName: string) {
  const row = wrapper.findAll(AVAILABLE_JOB).find(candidate => candidate.text().includes(characterName))
  expect(row, `${AVAILABLE_JOB} containing ${characterName}`).toBeDefined()
  return row as NonNullable<typeof row>
}

describe('Job link modal on a job shared between lines', () => {
  it('offers a runs field defaulting to the remaining runs and bounded between 1 and the remaining runs', () => {
    const wrapper = mountModal([SHARED_ISHTAR_JOB, UNSHARED_JOB])

    const sharedField = rowOf(wrapper, 'Aria Solberg').find(AVAILABLE_JOB_RUNS)
    const unsharedField = rowOf(wrapper, 'Dax Orlan').find(AVAILABLE_JOB_RUNS)

    expect(sharedField.exists()).toBe(true)
    expect(unsharedField.exists()).toBe(true)
    expect({
      shared: [sharedField.attributes('min'), sharedField.attributes('max'), (sharedField.element as HTMLInputElement).value],
      unshared: [unsharedField.attributes('min'), unsharedField.attributes('max'), (unsharedField.element as HTMLInputElement).value],
    }).toEqual({
      shared: ['1', '6', '6'],
      unshared: ['1', '7', '7'],
    })
  })

  it('shows the runs already assigned on a shared job only', () => {
    const wrapper = mountModal([SHARED_ISHTAR_JOB, UNSHARED_JOB])

    expect(rowOf(wrapper, 'Aria Solberg').find(AVAILABLE_JOB_SHARED).exists()).toBe(true)
    expect(rowOf(wrapper, 'Aria Solberg').find(AVAILABLE_JOB_SHARED).text()).toBe('4 / 10 runs already assigned')
    expect(rowOf(wrapper, 'Dax Orlan').find(AVAILABLE_JOB_SHARED).exists()).toBe(false)
  })

  it('keeps the link button while runs remain, even on a job already carrying a share in the project, and drops it when none remain', () => {
    const wrapper = mountModal([SHARED_VEXOR_JOB, FULLY_ASSIGNED_JOB])

    expect({
      sharedInProject: rowOf(wrapper, 'Brann Holt').find(AVAILABLE_JOB_LINK).exists(),
      fullyAssigned: rowOf(wrapper, 'Cyra Lune').find(AVAILABLE_JOB_LINK).exists(),
    }).toEqual({ sharedInProject: true, fullyAssigned: false })
  })

  it('emits link with the job and no runs when the field still holds the remaining runs', async () => {
    const wrapper = mountModal([SHARED_ISHTAR_JOB])

    await wrapper.get(AVAILABLE_JOB_LINK).trigger('click')

    expect(wrapper.emitted('link')).toStrictEqual([[SHARED_ISHTAR_JOB_ID, undefined]])
  })

  it('emits link with the job and the runs typed when they differ from the remaining runs', async () => {
    const wrapper = mountModal([SHARED_ISHTAR_JOB])

    expect(wrapper.find(AVAILABLE_JOB_RUNS).exists()).toBe(true)
    await wrapper.get(AVAILABLE_JOB_RUNS).setValue(2)
    await wrapper.get(AVAILABLE_JOB_LINK).trigger('click')

    expect(wrapper.emitted('link')).toStrictEqual([[SHARED_ISHTAR_JOB_ID, 2]])
  })
})
