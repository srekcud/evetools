import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectJournal from '@/components/group-industry/ProjectJournal.vue'
import type { ProjectActivityEvent, ProjectRunningJob } from '@/stores/group-industry/types'

enableAutoUnmount(afterEach)

const BLANK = /[\u00a0\u202f]/g
const LONG_PILOT_NAME = 'Theodosius Maximilian Valerius of the Far Reaches'
const NYX_ENDS_AT = '2026-11-09T12:00:00+00:00'

const NYX_JOBS: ProjectRunningJob = {
  characterName: 'Selene Vance',
  typeId: 23_913,
  typeName: 'Nyx',
  jobCount: 2,
  runs: 20,
  startedAt: '2026-11-09T08:00:00+00:00',
  endsAt: NYX_ENDS_AT,
  cost: 3_000_000,
}
const AVATAR_JOBS: ProjectRunningJob = {
  characterName: 'Orin Kade',
  typeId: 11_567,
  typeName: 'Avatar',
  jobCount: 3,
  runs: 9,
  startedAt: null,
  endsAt: '2026-11-09T18:30:00+00:00',
  cost: null,
}
const FEROX_JOBS: ProjectRunningJob = {
  characterName: LONG_PILOT_NAME,
  typeId: 16_227,
  typeName: 'Ferox',
  jobCount: 4,
  runs: 12,
  startedAt: '2026-11-09T07:00:00+00:00',
  endsAt: '2026-11-09T20:00:00+00:00',
  cost: 850_000,
}

const FEES_UPDATED: ProjectActivityEvent = {
  id: 'event-fees',
  kind: 'fees_updated',
  occurredAt: '2026-11-09T09:15:00+00:00',
  data: { brokerFeePercent: 2.8, salesTaxPercent: 3.4, previousBrokerFeePercent: 3.5, previousSalesTaxPercent: 3.4 },
}
const MEMBER_ACCEPTED: ProjectActivityEvent = {
  id: 'event-member',
  kind: 'member_accepted',
  occurredAt: '2026-11-09T08:40:00+00:00',
  data: { memberId: 'member-selene', characterName: 'Selene Vance' },
}
const JOBS_DETECTED: ProjectActivityEvent = {
  id: 'event-jobs',
  kind: 'jobs_detected',
  occurredAt: '2026-11-09T08:05:00+00:00',
  data: { characterName: 'Selene Vance', typeId: 23_913, typeName: 'Nyx', jobCount: 2, runs: 20 },
}

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

function text(key: string): string {
  expect(i18n.global.te(key, 'en')).toBe(true)
  return i18n.global.t(key).replace(BLANK, ' ')
}

function mountJournal(props: { events?: ProjectActivityEvent[]; runningJobs?: ProjectRunningJob[]; live?: boolean }): VueWrapper {
  return mount(ProjectJournal, {
    props: { events: [], runningJobs: [], live: false, ...props },
    global: { plugins: [i18n] },
  })
}

function textOf(wrapper: VueWrapper, selector: string): string {
  const found = wrapper.find(selector)
  return found.exists() ? found.text().replace(BLANK, ' ') : 'absent'
}

function runningJobSelector(job: ProjectRunningJob, part: string): string {
  return `[data-testid="journal-running-job-${job.typeId}"] [data-testid="journal-${part}"]`
}

afterEach(() => {
  vi.useRealTimers()
})

describe('project journal running jobs', () => {
  it('shows one card per group with the pilot, the product, the jobs and runs and the real cost', () => {
    const wrapper = mountJournal({ runningJobs: [NYX_JOBS, AVATAR_JOBS] })

    expect(wrapper.findAll('[data-testid^="journal-running-job-"]').map(card => card.attributes('data-testid'))).toEqual([
      'journal-running-job-23913',
      'journal-running-job-11567',
    ])
    expect({
      pilot: textOf(wrapper, runningJobSelector(NYX_JOBS, 'pilot')),
      product: textOf(wrapper, runningJobSelector(NYX_JOBS, 'product')),
      summary: textOf(wrapper, runningJobSelector(NYX_JOBS, 'summary')),
      cost: textOf(wrapper, runningJobSelector(NYX_JOBS, 'cost')),
    }).toEqual({
      pilot: 'Selene Vance',
      product: 'Nyx',
      summary: '2 jobs × 20 runs',
      cost: '3,000,000 ISK',
    })
  })

  it('shows an unknown cost as unknown, never as zero', () => {
    const wrapper = mountJournal({ runningJobs: [AVATAR_JOBS] })

    const shown = textOf(wrapper, runningJobSelector(AVATAR_JOBS, 'cost'))
    expect(shown).not.toBe('absent')
    expect(shown).not.toContain('0 ISK')
    expect(shown).toBe(text('groupIndustry.journal.unknownCost'))
  })

  it('truncates a long pilot name and keeps it whole in the title', () => {
    const wrapper = mountJournal({ runningJobs: [FEROX_JOBS] })

    const pilot = wrapper.find(runningJobSelector(FEROX_JOBS, 'pilot'))
    expect({ truncated: pilot.exists() && pilot.classes('truncate'), title: pilot.attributes('title') }).toEqual({
      truncated: true,
      title: LONG_PILOT_NAME,
    })
  })

  it('counts down every second to the end of the jobs, then shows them as finished', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(Date.parse(NYX_ENDS_AT) - 3_725_000)
    const wrapper = mountJournal({ runningJobs: [NYX_JOBS] })
    const countdown = () => textOf(wrapper, runningJobSelector(NYX_JOBS, 'countdown'))
    const atStart = countdown()

    await vi.advanceTimersByTimeAsync(1_000)
    await nextTick()
    const afterOneSecond = countdown()
    await vi.advanceTimersByTimeAsync(3_724_000)
    await nextTick()

    expect({ atStart, afterOneSecond, atTheEnd: countdown() }).toEqual({
      atStart: '01:02:05',
      afterOneSecond: '01:02:04',
      atTheEnd: text('groupIndustry.journal.finished'),
    })
  })
})

describe('project journal activity feed', () => {
  it('lists one line per event in the order given, each with the label of its kind', () => {
    const wrapper = mountJournal({ events: [FEES_UPDATED, MEMBER_ACCEPTED, JOBS_DETECTED] })

    expect(wrapper.findAll('[data-testid^="journal-event-"]').map(row => row.attributes('data-testid'))).toEqual([
      'journal-event-event-fees',
      'journal-event-event-member',
      'journal-event-event-jobs',
    ])
    expect({
      fees: textOf(wrapper, '[data-testid="journal-event-event-fees"]').includes(text('groupIndustry.journal.kinds.fees_updated')),
      member: textOf(wrapper, '[data-testid="journal-event-event-member"]').includes(text('groupIndustry.journal.kinds.member_accepted')),
      jobs: textOf(wrapper, '[data-testid="journal-event-event-jobs"]').includes(text('groupIndustry.journal.kinds.jobs_detected')),
      empty: wrapper.find('[data-testid="journal-empty"]').exists(),
    }).toEqual({ fees: true, member: true, jobs: true, empty: false })
  })

  it('shows the live badge only while the connection is open', () => {
    const connected = mountJournal({ events: [FEES_UPDATED], live: true })
    const disconnected = mountJournal({ events: [FEES_UPDATED], live: false })

    expect({
      connected: connected.find('[data-testid="journal-live-badge"]').exists(),
      disconnected: disconnected.find('[data-testid="journal-live-badge"]').exists(),
    }).toEqual({ connected: true, disconnected: false })
  })

  it('shows an empty state and no running job card when there is neither an event nor a running job', () => {
    const wrapper = mountJournal({})

    expect({
      empty: textOf(wrapper, '[data-testid="journal-empty"]'),
      runningJobCards: wrapper.findAll('[data-testid^="journal-running-job-"]').length,
      eventRows: wrapper.findAll('[data-testid^="journal-event-"]').length,
    }).toEqual({ empty: text('groupIndustry.journal.empty'), runningJobCards: 0, eventRows: 0 })
  })
})
