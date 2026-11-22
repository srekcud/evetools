import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import UnassignedJobsPanel from '@/components/group-industry/UnassignedJobsPanel.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import type { UnassignedJob } from '@/stores/group-industry/types'
import { useUnassignedJobsStore } from '@/stores/group-industry/unassignedJobs'
import { unassignedCandidate, unassignedJob } from './unassignedJobFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const LIST_PATH = '/industry/unassigned-jobs'
const CARD = '[data-testid="unassigned-job"]'
const CANDIDATE = '[data-testid="unassigned-job-candidate"]'
const ASSIGN_BUTTON = '[data-testid="unassign-assign"]'
const DISMISS_BUTTON = '[data-testid="unassign-dismiss"]'

const ISHTAR_JOB = unassignedJob({
  esiJobId: 9_101,
  candidates: [
    unassignedCandidate({ projectId: 'project-ishtar-322', projectName: 'Ishtar batch', lineId: 'line-ishtar-hull', lineName: 'Ishtar', remainingRuns: 6, score: 9, signals: ['intent', 'exact_fit'], oversized: false }),
    unassignedCandidate({ projectId: 'project-ishtar-411', projectName: 'Ishtar refit', lineId: 'line-ishtar-spare', lineName: 'Ishtar spare', remainingRuns: 2, score: 4, signals: [], oversized: true }),
  ],
})
const VEXOR_JOB = unassignedJob({
  esiJobId: 9_102,
  characterName: 'Beta Pilot',
  blueprintTypeId: 17_841,
  productTypeId: 17_840,
  productTypeName: 'Vexor Navy Issue',
  runs: 1,
  stationId: 60_008_494,
  stationName: 'Perimeter - Tranquility Trading Tower',
  candidates: [
    unassignedCandidate({ projectId: 'project-vexor-7', projectName: 'Vexor run', lineId: 'line-vexor-first', lineName: 'Vexor Navy Issue', remainingRuns: 3, score: 6, signals: ['place', 'structure'] }),
    unassignedCandidate({ projectId: 'project-vexor-9', projectName: 'Vexor stock', lineId: 'line-vexor-second', lineName: 'Vexor Navy Issue copy', remainingRuns: 5, score: 6, signals: [] }),
  ],
})

let servedJobs: UnassignedJob[]

function textOf(parent: VueWrapper | DOMWrapper<Element>, testId: string): string | undefined {
  const element = parent.find(`[data-testid="${testId}"]`)
  return element.exists() ? element.text().replace(/[\s\u00a0\u202f]+/g, ' ') : undefined
}

async function mountPanel(jobs: UnassignedJob[]): Promise<VueWrapper> {
  servedJobs = jobs
  setActivePinia(createPinia())
  await useUnassignedJobsStore().load()
  const wrapper = mount(UnassignedJobsPanel, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => (endpoint === LIST_PATH ? servedJobs : undefined))
})

describe('UnassignedJobsPanel', () => {
  it('titles the section with the number of jobs and shows one card per job with its character, product, runs and station', async () => {
    const wrapper = await mountPanel([ISHTAR_JOB, VEXOR_JOB])

    expect({
      title: textOf(wrapper, 'unassigned-jobs-title'),
      cards: wrapper.findAll(CARD).map(card => ({
        character: textOf(card, 'unassigned-job-character'),
        product: textOf(card, 'unassigned-job-product'),
        runs: textOf(card, 'unassigned-job-runs'),
        station: textOf(card, 'unassigned-job-station'),
      })),
    }).toEqual({
      title: 'Jobs to assign (2)',
      cards: [
        { character: 'Alpha', product: 'Ishtar', runs: '4 runs', station: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant' },
        { character: 'Beta Pilot', product: 'Vexor Navy Issue', runs: '1 run', station: 'Perimeter - Tranquility Trading Tower' },
      ],
    })
  })

  it('lists the candidate lines of a job in the order served, with project, line, remaining runs, signal chips and the oversized flag', async () => {
    const wrapper = await mountPanel([ISHTAR_JOB, VEXOR_JOB])

    const candidatesOf = (cardIndex: number) => wrapper.findAll(CARD)[cardIndex].findAll(CANDIDATE).map(candidate => ({
      project: textOf(candidate, 'unassigned-candidate-project'),
      line: textOf(candidate, 'unassigned-candidate-line'),
      remaining: textOf(candidate, 'unassigned-candidate-remaining'),
      signals: candidate.findAll('[data-testid="unassigned-candidate-signal"]').map(signal => signal.text()),
      oversized: textOf(candidate, 'unassigned-candidate-oversized'),
    }))

    expect({ ishtar: candidatesOf(0), vexor: candidatesOf(1) }).toEqual({
      ishtar: [
        { project: 'Ishtar batch', line: 'Ishtar', remaining: '6 runs left', signals: ['Intended', 'Exact fit'], oversized: undefined },
        { project: 'Ishtar refit', line: 'Ishtar spare', remaining: '2 runs left', signals: [], oversized: 'Oversized' },
      ],
      vexor: [
        { project: 'Vexor run', line: 'Vexor Navy Issue', remaining: '3 runs left', signals: ['Project container', 'Planned structure'], oversized: undefined },
        { project: 'Vexor stock', line: 'Vexor Navy Issue copy', remaining: '5 runs left', signals: [], oversized: undefined },
      ],
    })
  })

  it('assigns the job to the line of the clicked candidate, in one click without confirmation', async () => {
    const wrapper = await mountPanel([ISHTAR_JOB, VEXOR_JOB])
    const assignButtons = wrapper.findAll(CARD)[1].findAll(ASSIGN_BUTTON)
    expect(assignButtons.map(button => button.text())).toEqual(['Assign', 'Assign'])

    await assignButtons[1].trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint !== LIST_PATH)).toEqual([
      ['/industry/unassigned-jobs/9102/assign', { method: 'POST', body: JSON.stringify({ lineId: 'line-vexor-second' }) }],
    ])
  })

  it('dismisses the job of the clicked card as not for a project', async () => {
    const wrapper = await mountPanel([ISHTAR_JOB, VEXOR_JOB])
    const dismissButtons = wrapper.findAll(DISMISS_BUTTON)
    expect(dismissButtons.map(button => button.text())).toEqual(['Not for a project', 'Not for a project'])

    await dismissButtons[1].trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint !== LIST_PATH)).toEqual([
      ['/industry/unassigned-jobs/9102/dismiss', { method: 'POST' }],
    ])
  })

  it('renders nothing when there is no job to assign', async () => {
    const wrapper = await mountPanel([])

    expect({ panel: wrapper.find('[data-testid="unassigned-jobs-panel"]').exists(), cards: wrapper.findAll(CARD).length }).toEqual({ panel: false, cards: 0 })
  })
})
