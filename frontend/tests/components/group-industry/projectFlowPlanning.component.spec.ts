import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectFlowPlanning from '@/components/group-industry/ProjectFlowPlanning.vue'
import type { FlowGrid } from '@/components/group-industry/projectFlow'
import { apiRequest } from '@/services/api'
import type { BomItem, ProjectPlanning } from '@/stores/group-industry/types'
import { TEST_LOCALES, messageOf, type TestLocale } from '../../localeTexts'
import { HECATE_LINE_ID, ISHTAR_LINE_ID, PLANNING_PROJECT_ID, RAM_LINE_ID, planningJob } from '../../projectPlanningData'
import { STATION_A_ID, STATION_B_ID, flowCard, flowGrid, flowRow } from './projectFlowFixtures'
import {
  HECATE_PLANNED_JOB,
  ISHTAR_PLANNED_JOB,
  PLANNING_NOW,
  hoursFromNow,
  planningOfJobs,
  span,
} from './projectFlowPlanningFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const LONG_TYPE_NAME = 'Capital Ship Assembly Array Blueprint Copy for the Ishtar Heavy Assault Cruiser Fleet Refit Programme'
const ISHTAR_TYPE_ID = 12_005
const HECATE_TYPE_ID = 35_683

const EMPTY_TEXT: Record<TestLocale, string> = { fr: 'Rien à planifier', en: 'Nothing to plan' }
const DONE_END: Record<TestLocale, string> = { fr: 'Fait', en: 'Done' }
const DAYS_END: Record<TestLocale, string> = { fr: 'J+2', en: 'D+2' }
const TRANSFERS_NOTE: Record<TestLocale, string> = {
  fr: 'Transferts entre stations non inclus',
  en: 'Transfers between stations not included',
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(PLANNING_NOW)
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

function twoStationGrid(): FlowGrid {
  return flowGrid([
    flowRow({ key: `station:${STATION_A_ID}`, locationId: STATION_A_ID, name: 'Azbel' }, {
      out: [flowCard('out', { typeName: 'Hecate', typeId: HECATE_TYPE_ID })],
    }),
    flowRow({ key: `station:${STATION_B_ID}`, locationId: STATION_B_ID, name: 'Athanor' }, {
      inProduction: [flowCard('inProduction', { typeName: 'Ishtar', typeId: ISHTAR_TYPE_ID })],
    }),
  ])
}

async function mountFlowPlanning(
  answer: ProjectPlanning | Error,
  locale: TestLocale = 'en',
  grid: FlowGrid = twoStationGrid(),
  bomItems: BomItem[] = [],
): Promise<VueWrapper> {
  if (answer instanceof Error) vi.mocked(apiRequest).mockRejectedValue(answer)
  else vi.mocked(apiRequest).mockResolvedValue(answer)
  setActivePinia(createPinia())
  const wrapper = mount(ProjectFlowPlanning, {
    props: { projectId: PLANNING_PROJECT_ID, grid, bomItems },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
  await flushPromises()
  return wrapper
}

function squashed(text: string): string {
  return text.replace(/[\u00a0\u202f]/g, ' ').replace(/\s+/g, ' ').trim()
}

type FoundElement = ReturnType<VueWrapper['find']>

function elementOf(wrapper: Pick<VueWrapper, 'find'>, selector: string): FoundElement {
  const found = wrapper.find(selector)
  expect(found.exists(), `element ${selector}`).toBe(true)
  return found
}

function byTestId(wrapper: Pick<VueWrapper, 'find'>, testId: string): FoundElement {
  return elementOf(wrapper, `[data-testid="${testId}"]`)
}

function textOfTestId(wrapper: VueWrapper, testId: string): string {
  return squashed(byTestId(wrapper, testId).text())
}

function barLineIdsOf(wrapper: VueWrapper, stationTestId: string): string[] {
  return byTestId(wrapper, stationTestId).findAll('[data-testid^="flow-planning-bar-"]')
    .map(bar => (bar.attributes('data-testid') ?? '').replace('flow-planning-bar-', ''))
}

describe('ProjectFlowPlanning', () => {
  it('says there is nothing to plan, with no station and no bar, when the plan has no job and nothing unscheduled', async () => {
    for (const locale of TEST_LOCALES) {
      const wrapper = await mountFlowPlanning(planningOfJobs([]), locale)

      expect(textOfTestId(wrapper, 'flow-planning-empty'), locale).toBe(EMPTY_TEXT[locale])
      expect(wrapper.findAll('[data-testid^="flow-planning-station-"]'), locale).toHaveLength(0)
      expect(wrapper.findAll('[data-testid^="flow-planning-bar-"]'), locale).toHaveLength(0)
      expect(wrapper.find('[data-testid="flow-planning-error"]').exists(), locale).toBe(false)
    }
  })

  it('shows an alert with the error and no bar, never an empty planning, when the read fails', async () => {
    const wrapper = await mountFlowPlanning(new Error('Planning unavailable'))

    const alert = byTestId(wrapper, 'flow-planning-error')
    expect(alert.attributes('role')).toBe('alert')
    expect(alert.text()).toContain('Planning unavailable')
    expect(wrapper.find('[data-testid="flow-planning-empty"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid^="flow-planning-bar-"]')).toHaveLength(0)
  })

  it('draws one bar per job in the station of its job, with its state, a done bar ending on the done label and a bar ending in two days and three hours on D+2', async () => {
    const doneJob = planningJob({ ...HECATE_PLANNED_JOB, real: { ...span(-3, -1), status: 'done' } })
    const runningJob = planningJob({ ...ISHTAR_PLANNED_JOB, real: { start: hoursFromNow(-1), end: hoursFromNow(51), status: 'active' } })
    const waitingJob = planningJob({
      lineId: RAM_LINE_ID,
      typeId: ISHTAR_TYPE_ID,
      typeName: 'Ishtar Second Run',
      planned: span(5, 8),
      wait: { kind: 'waiting_job', names: ['Ishtar'], lateSeconds: 3_600 },
    })

    for (const locale of TEST_LOCALES) {
      const wrapper = await mountFlowPlanning(planningOfJobs([doneJob, runningJob, waitingJob]), locale)

      expect(barLineIdsOf(wrapper, `flow-planning-station-station:${STATION_A_ID}`), locale).toEqual([HECATE_LINE_ID])
      expect(barLineIdsOf(wrapper, `flow-planning-station-station:${STATION_B_ID}`), locale).toEqual([ISHTAR_LINE_ID, RAM_LINE_ID])
      expect([HECATE_LINE_ID, ISHTAR_LINE_ID, RAM_LINE_ID].map(lineId => byTestId(wrapper, `flow-planning-bar-${lineId}`).attributes('data-state')), locale)
        .toEqual(['done', 'running', 'waiting'])
      expect(textOfTestId(wrapper, `flow-planning-end-${HECATE_LINE_ID}`), locale).toBe(DONE_END[locale])
      expect(textOfTestId(wrapper, `flow-planning-end-${ISHTAR_LINE_ID}`), locale).toBe(DAYS_END[locale])
      expect(wrapper.findAll('[data-testid="flow-planning-station-title"]').map(title => squashed(title.text().toLowerCase())), locale).toEqual(['azbel', 'athanor'])
      expect(wrapper.find('[data-testid="flow-planning-now"]').exists(), locale).toBe(true)
    }
  })

  it('truncates a very long type name inside its column and keeps the full name in the title', async () => {
    const longNamedJob = planningJob({ ...ISHTAR_PLANNED_JOB, typeName: LONG_TYPE_NAME })

    const wrapper = await mountFlowPlanning(planningOfJobs([longNamedJob]))

    const typeName = byTestId(wrapper, `flow-planning-type-${ISHTAR_LINE_ID}`)
    expect(LONG_TYPE_NAME.length).toBeGreaterThan(80)
    expect(typeName.attributes('title')).toBe(LONG_TYPE_NAME)
    expect(typeName.classes()).toContain('truncate')
  })

  it('says the transfers between stations are not included', async () => {
    for (const locale of TEST_LOCALES) {
      const wrapper = await mountFlowPlanning(planningOfJobs([ISHTAR_PLANNED_JOB]), locale)

      expect(textOfTestId(wrapper, 'flow-planning-transfers-note'), locale).toBe(TRANSFERS_NOTE[locale])
    }
  })

  it('shows the expected end, the planned end when none is expected, and the end as unknown, never 0, with the bars still drawn when neither exists', async () => {
    const expectedEnd = hoursFromNow(9)
    const plannedEnd = hoursFromNow(7)
    const withExpected = await mountFlowPlanning(planningOfJobs([ISHTAR_PLANNED_JOB], { expectedEnd, plannedEnd }))
    const withPlannedOnly = await mountFlowPlanning(planningOfJobs([ISHTAR_PLANNED_JOB], { expectedEnd: null, plannedEnd }))
    const withNeither = await mountFlowPlanning(planningOfJobs([ISHTAR_PLANNED_JOB], { expectedEnd: null, plannedEnd: null }), 'fr')

    expect([withExpected, withPlannedOnly, withNeither].map(wrapper => byTestId(wrapper, 'flow-planning-expected-end').attributes('data-value'))).toEqual([
      expectedEnd,
      plannedEnd,
      undefined,
    ])
    const unknown = elementOf(withNeither, '[data-testid="flow-planning-expected-end"] [data-testid="flow-planning-unknown"]')
    expect(unknown.attributes('title')).toBe(messageOf('fr', 'common.unknownValue'))
    expect(byTestId(withNeither, 'flow-planning-expected-end').text()).not.toMatch(/\b0\b/)
    expect(barLineIdsOf(withNeither, `flow-planning-station-station:${STATION_B_ID}`)).toEqual([ISHTAR_LINE_ID])
  })
})
