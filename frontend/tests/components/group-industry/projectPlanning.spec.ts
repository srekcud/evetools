import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectPlanning from '@/components/group-industry/ProjectPlanning.vue'
import { apiRequest } from '@/services/api'
import type { ProjectPlanning as ProjectPlanningData } from '@/stores/group-industry/types'
import { messageOf, type TestLocale } from '../../localeTexts'
import {
  ARIA_SOLBERG,
  ARIA_WITH_THE_RAM_AND_THE_ISHTAR,
  BRIN_SOLBERG,
  CORIN_VALE,
  CORIN_WITHOUT_SKILLS,
  HECATE_LINE_ID,
  HULL_LINE_ID,
  ISHTAR_LINE_ID,
  LATE_PLANNING,
  NOCXIUM_LINE_ID,
  PLANNING_PROJECT_ID,
  planningCharacter,
  planningJob,
  projectPlanning,
  RAM_LINE_ID,
  RAM_NAME,
} from '../../projectPlanningData'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const LONG_CHARACTER_NAME = 'Aria Solberg-Hollenbeck of the Seventh Industrial Reclamation Syndicate Expeditionary Wing'
const LONG_TYPE_NAME = 'Capital Ship Assembly Array Blueprint Copy for the Ishtar Heavy Assault Cruiser Fleet Refit Programme'
const ZERO_AS_A_WORD = /\b0\b/

type FoundElement = Omit<DOMWrapper<Element>, 'exists'>
type Container = VueWrapper | FoundElement

async function mountPlanning(planning: ProjectPlanningData, locale: TestLocale = 'en'): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue(planning)
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ProjectPlanning, { props: { projectId: PLANNING_PROJECT_ID }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function byTestId(parent: Container, testId: string): FoundElement {
  const found = (parent as VueWrapper).find(`[data-testid="${testId}"]`)
  expect(found.exists(), `element ${testId}`).toBe(true)
  return found as DOMWrapper<Element>
}

function laneOf(wrapper: VueWrapper, characterName: string): FoundElement {
  return byTestId(wrapper, `planning-lane-${characterName}`)
}

function assignmentRowOf(wrapper: VueWrapper, characterName: string): FoundElement {
  return byTestId(wrapper, `planning-assignment-${characterName}`)
}

function testIdsOf(parent: Container, prefix: string): string[] {
  return (parent as VueWrapper).findAll(`[data-testid^="${prefix}"]`).map(found => found.attributes('data-testid') ?? '')
}

function unknownOf(parent: FoundElement): FoundElement {
  return byTestId(parent, 'planning-unknown')
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ProjectPlanning figures', () => {
  it('shows the planned end, the expected end and the delay with the job it waits for', async () => {
    const wrapper = await mountPlanning(LATE_PLANNING)

    const delay = byTestId(wrapper, 'planning-delay')
    const cause = byTestId(wrapper, 'planning-delay-cause')

    expect({
      plannedEnd: byTestId(wrapper, 'planning-planned-end').attributes('data-value'),
      expectedEnd: byTestId(wrapper, 'planning-expected-end').attributes('data-value'),
      delaySeconds: delay.attributes('data-seconds'),
      delayDirection: delay.attributes('data-direction'),
      causeKind: cause.attributes('data-kind'),
    }).toEqual({
      plannedEnd: '2026-11-08T10:00:00+00:00',
      expectedEnd: '2026-11-08T14:00:00+00:00',
      delaySeconds: '14400',
      delayDirection: 'late',
      causeKind: 'waiting_job',
    })
    expect(cause.text()).toContain(RAM_NAME)
  })

  it('shows a negative delay as an advance, never as 0 or as a clamped duration', async () => {
    const wrapper = await mountPlanning(projectPlanning({
      plannedEnd: '2026-11-08T10:00:00+00:00',
      expectedEnd: '2026-11-08T08:30:00+00:00',
      delaySeconds: -5_400,
      delayCause: null,
      characters: [ARIA_WITH_THE_RAM_AND_THE_ISHTAR],
    }))

    const delay = byTestId(wrapper, 'planning-delay')

    expect(delay.attributes('data-seconds')).toBe('-5400')
    expect(delay.attributes('data-direction')).toBe('early')
    expect(delay.text()).toMatch(/1.*30/)
    expect(delay.text()).not.toMatch(/[-−]/)
    expect(delay.text()).not.toMatch(ZERO_AS_A_WORD)
    expect(wrapper.find('[data-testid="planning-delay-cause"]').exists()).toBe(false)
  })

  it('shows the three figures as unknown, never as 0 or as a date, with the cause of each unscheduled job when no end can be planned', async () => {
    const wrapper = await mountPlanning(projectPlanning({
      characters: [CORIN_WITHOUT_SKILLS],
      unscheduled: [
        { lineId: HULL_LINE_ID, typeName: 'Hull Rework', blueprintName: null, reason: 'no_eligible_worker' },
        { lineId: NOCXIUM_LINE_ID, typeName: 'Nocxium Reaction', blueprintName: null, reason: 'duration_unknown' },
        { lineId: ISHTAR_LINE_ID, typeName: 'Ishtar', blueprintName: null, reason: 'dependency_unscheduled' },
      ],
    }))

    const figures = ['planning-planned-end', 'planning-expected-end', 'planning-delay'].map(testId => byTestId(wrapper, testId))
    const unscheduled = byTestId(wrapper, 'planning-unscheduled')

    for (const figure of figures) {
      expect(unknownOf(figure).attributes('title')).toBe(messageOf('en', 'common.unknownValue'))
      expect(figure.attributes('data-value')).toBeUndefined()
      expect(figure.attributes('data-seconds')).toBeUndefined()
      expect(figure.text()).not.toMatch(ZERO_AS_A_WORD)
    }
    expect(wrapper.find('[data-testid="planning-delay-cause"]').exists()).toBe(false)
    expect(testIdsOf(unscheduled, 'planning-unscheduled-').map(testId => [testId, byTestId(unscheduled, testId).attributes('data-reason')])).toEqual([
      [`planning-unscheduled-${HULL_LINE_ID}`, 'no_eligible_worker'],
      [`planning-unscheduled-${NOCXIUM_LINE_ID}`, 'duration_unknown'],
      [`planning-unscheduled-${ISHTAR_LINE_ID}`, 'dependency_unscheduled'],
    ])
    expect(byTestId(unscheduled, `planning-unscheduled-${HULL_LINE_ID}`).text()).toContain('Hull Rework')
    expect(byTestId(unscheduled, `planning-unscheduled-${NOCXIUM_LINE_ID}`).text()).toContain('Nocxium Reaction')
  })
})

describe('ProjectPlanning gantt', () => {
  it('draws one lane per character with its jobs, the planned bar, the real bar with its status and the wait', async () => {
    const wrapper = await mountPlanning(LATE_PLANNING)

    const ariaLane = laneOf(wrapper, ARIA_SOLBERG)
    const brinLane = laneOf(wrapper, BRIN_SOLBERG)
    const ramJob = byTestId(ariaLane, `planning-job-${RAM_LINE_ID}`)
    const ishtarJob = byTestId(ariaLane, `planning-job-${ISHTAR_LINE_ID}`)
    const hecateJob = byTestId(brinLane, `planning-job-${HECATE_LINE_ID}`)

    expect(testIdsOf(wrapper, 'planning-lane-')).toEqual([`planning-lane-${ARIA_SOLBERG}`, `planning-lane-${BRIN_SOLBERG}`])
    expect(testIdsOf(ariaLane, 'planning-job-')).toEqual([`planning-job-${RAM_LINE_ID}`, `planning-job-${ISHTAR_LINE_ID}`])
    expect(testIdsOf(brinLane, 'planning-job-').map(testId => byTestId(brinLane, testId).attributes('data-activity'))).toEqual(['manufacturing', 'reaction'])
    expect(byTestId(ishtarJob, 'bar-planned').attributes()).toMatchObject({
      'data-start': '2026-11-08T08:00:00+00:00',
      'data-end': '2026-11-08T10:00:00+00:00',
    })
    expect(byTestId(ishtarJob, 'bar-real').attributes()).toMatchObject({
      'data-start': '2026-11-08T12:00:00+00:00',
      'data-end': '2026-11-08T14:00:00+00:00',
      'data-status': 'active',
    })
    expect(byTestId(ishtarJob, 'job-wait').attributes('data-kind')).toBe('waiting_job')
    expect(byTestId(ishtarJob, 'job-wait').text()).toContain(RAM_NAME)
    expect(byTestId(ramJob, 'bar-real').attributes('data-status')).toBe('done')
    expect(ramJob.find('[data-testid="job-wait"]').exists()).toBe(false)
    expect(byTestId(hecateJob, 'bar-planned').attributes('data-end')).toBe('2026-11-08T09:30:00+00:00')
    expect(hecateJob.find('[data-testid="bar-real"]').exists()).toBe(false)
    expect(hecateJob.find('[data-testid="job-wait"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid="planning-now"]')).toHaveLength(1)
  })

  it('shows a character without synchronized skills as unknown with its cause, without job, 0 or date', async () => {
    const wrapper = await mountPlanning(projectPlanning({
      plannedEnd: '2026-11-08T10:00:00+00:00',
      expectedEnd: '2026-11-08T14:00:00+00:00',
      delaySeconds: 14_400,
      characters: [ARIA_WITH_THE_RAM_AND_THE_ISHTAR, CORIN_WITHOUT_SKILLS],
    }), 'fr')

    const lane = laneOf(wrapper, CORIN_VALE)
    const row = assignmentRowOf(wrapper, CORIN_VALE)

    expect(unknownOf(byTestId(lane, 'lane-unknown')).attributes('title')).toBe(messageOf('fr', 'common.unknownValue'))
    expect(lane.text()).toContain('non synchronisées')
    expect(lane.text()).not.toMatch(ZERO_AS_A_WORD)
    expect(testIdsOf(lane, 'planning-job-')).toEqual([])
    expect(lane.findAll('[data-testid="bar-planned"], [data-testid="bar-real"]')).toHaveLength(0)
    for (const cellId of [
      'assignment-slots-manufacturing',
      'assignment-slots-reaction',
      'assignment-skill-industry',
      'assignment-skill-advancedIndustry',
      'assignment-skill-reactions',
      'assignment-ends-at',
    ]) {
      expect(unknownOf(byTestId(row, cellId)).attributes('title')).toBe(messageOf('fr', 'common.unknownValue'))
    }
    expect(row.text()).not.toMatch(ZERO_AS_A_WORD)
  })
})

describe('ProjectPlanning who launches what', () => {
  it('lists for each character its manufacturing and reaction slots, its time skills, the jobs it is given and its end', async () => {
    const wrapper = await mountPlanning(LATE_PLANNING)

    const rowsOf = (characterName: string) => {
      const row = assignmentRowOf(wrapper, characterName)
      return {
        manufacturingSlots: byTestId(row, 'assignment-slots-manufacturing').text(),
        reactionSlots: byTestId(row, 'assignment-slots-reaction').text(),
        industry: byTestId(row, 'assignment-skill-industry').text(),
        advancedIndustry: byTestId(row, 'assignment-skill-advancedIndustry').text(),
        reactions: byTestId(row, 'assignment-skill-reactions').text(),
        end: byTestId(row, 'assignment-ends-at').attributes('data-value'),
      }
    }

    expect(testIdsOf(wrapper, 'planning-assignment-')).toEqual([`planning-assignment-${ARIA_SOLBERG}`, `planning-assignment-${BRIN_SOLBERG}`])
    expect(rowsOf(ARIA_SOLBERG)).toEqual({
      manufacturingSlots: '4',
      reactionSlots: '2',
      industry: '4',
      advancedIndustry: '3',
      reactions: '2',
      end: '2026-11-08T14:00:00+00:00',
    })
    expect(rowsOf(BRIN_SOLBERG)).toEqual({
      manufacturingSlots: '2',
      reactionSlots: '6',
      industry: '5',
      advancedIndustry: '1',
      reactions: '4',
      end: '2026-11-08T11:15:00+00:00',
    })
    const ariaJobs = byTestId(assignmentRowOf(wrapper, ARIA_SOLBERG), 'assignment-jobs').text()
    const brinJobs = byTestId(assignmentRowOf(wrapper, BRIN_SOLBERG), 'assignment-jobs').text()
    expect(ariaJobs).toContain(RAM_NAME)
    expect(ariaJobs).toContain('Ishtar')
    expect(ariaJobs).not.toContain('Hecate')
    expect(brinJobs).toContain('Hecate')
    expect(brinJobs).toContain('Nocxium Reaction')
  })

  it('warns that the durations carry no structure bonus only when the API says so', async () => {
    const withoutBonus = await mountPlanning(LATE_PLANNING)
    const withoutBonusNotice = withoutBonus.find('[data-testid="planning-structure-notice"]').exists()
    const withBonus = await mountPlanning({ ...LATE_PLANNING, structureTimeBonusIncluded: true })

    expect(withoutBonusNotice).toBe(true)
    expect(withBonus.find('[data-testid="planning-structure-notice"]').exists()).toBe(false)
  })
})

describe('ProjectPlanning of a group project', () => {
  it('shows the ends and the delay without lane, assignment or missing-character message when the API hides the lanes', async () => {
    const wrapper = await mountPlanning(projectPlanning({
      plannedEnd: '2026-11-08T10:00:00+00:00',
      expectedEnd: '2026-11-08T12:00:00+00:00',
      delaySeconds: 7_200,
      lanesShown: false,
      characters: [],
    }))

    expect(byTestId(wrapper, 'planning-planned-end').attributes('data-value')).toBe('2026-11-08T10:00:00+00:00')
    expect(byTestId(wrapper, 'planning-delay').attributes('data-direction')).toBe('late')
    expect(wrapper.find('[data-testid="planning-gantt"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="planning-no-characters"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="assignment-name"]').exists()).toBe(false)
  })

  it('still says there is no character when the lanes are shown and the player has none', async () => {
    const wrapper = await mountPlanning(projectPlanning({ lanesShown: true, characters: [] }))

    expect(wrapper.find('[data-testid="planning-no-characters"]').exists()).toBe(true)
  })
})

describe('ProjectPlanning long names', () => {
  it('truncates a very long character name and type name with their full name as title in the gantt and in the table', async () => {
    const wrapper = await mountPlanning(projectPlanning({
      characters: [planningCharacter({
        characterName: LONG_CHARACTER_NAME,
        endsAt: '2026-11-08T10:00:00+00:00',
        jobs: [planningJob({
          lineId: ISHTAR_LINE_ID,
          typeName: LONG_TYPE_NAME,
          planned: { start: '2026-11-08T08:00:00+00:00', end: '2026-11-08T10:00:00+00:00' },
        })],
      })],
    }))

    const laneName = byTestId(wrapper, 'lane-name')
    const jobName = byTestId(wrapper, 'job-name')
    const rowName = byTestId(wrapper, 'assignment-name')

    expect(LONG_CHARACTER_NAME.length).toBeGreaterThan(80)
    expect(LONG_TYPE_NAME.length).toBeGreaterThan(80)
    expect(laneName.attributes('title')).toBe(LONG_CHARACTER_NAME)
    expect(laneName.classes()).toContain('truncate')
    expect(jobName.attributes('title')).toBe(LONG_TYPE_NAME)
    expect(jobName.classes()).toContain('truncate')
    expect(rowName.attributes('title')).toBe(LONG_CHARACTER_NAME)
    expect(rowName.classes()).toContain('truncate')
  })
})
