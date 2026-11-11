import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import IndustrySlotsSection from '@/components/industry/IndustrySlotsSection.vue'
import { apiRequest } from '@/services/api'
import type { SlotTrackerData, SlotTrackerJob, TimelineJob } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const EmptyView = { render: () => null }
const TABLE_LINK = '[data-testid="slot-job-project-link"]'
const TIMELINE_LINK = '[data-testid="timeline-job-project-link"]'

const RIFTER_PROJECT_ID = '0194f2a0-6b1c-7d3e-8a4f-1c2b3d4e5f60'
const MERLIN_PROJECT_ID = '0194f2a0-6b1c-7d3e-8a4f-1c2b3d4e5f61'
const RIFTER_PROJECT_NAME = 'Rifter batch of the corporation hangar restock for the november deployment'

function tableJob(jobId: number, productTypeId: number, productTypeName: string, project: SlotTrackerJob['project']): SlotTrackerJob {
  return {
    jobId,
    productTypeId,
    productTypeName,
    activityType: 'manufacturing',
    runs: 5,
    progress: 40,
    timeLeftSeconds: 3_600 * (jobId - 7_539_000),
    facilityName: 'Raitaru Heron Works',
    startDate: '2026-11-10T08:00:00+00:00',
    endDate: '2026-11-10T20:00:00+00:00',
    project,
  }
}

function timelineJob(job: SlotTrackerJob): TimelineJob {
  return {
    jobId: job.jobId,
    characterName: 'Ilyra Castell',
    productTypeId: job.productTypeId,
    productTypeName: job.productTypeName,
    activityType: job.activityType,
    runs: job.runs,
    timeLeftSeconds: job.timeLeftSeconds,
    endDate: job.endDate,
    project: job.project,
  }
}

const RIFTER_JOB = tableJob(7_539_001, 587, 'Rifter', { id: RIFTER_PROJECT_ID, name: RIFTER_PROJECT_NAME })
const MERLIN_JOB_UNNAMED_PROJECT = tableJob(7_539_002, 603, 'Merlin', { id: MERLIN_PROJECT_ID, name: null })
const KESTREL_JOB_WITHOUT_PROJECT = tableJob(7_539_003, 602, 'Kestrel', null)

const SLOTS: SlotTrackerData = {
  jobsSyncedAt: null,
  globalKpis: {
    manufacturing: { used: 3, max: 10, percent: 30 },
    reaction: { used: 0, max: 5, percent: 0 },
    science: { used: 0, max: 8, percent: 0 },
  },
  characters: [{
    characterId: 2_150_001,
    characterName: 'Ilyra Castell',
    isMain: true,
    slots: {
      manufacturing: { used: 3, max: 10 },
      reaction: { used: 0, max: 5 },
      science: { used: 0, max: 8 },
    },
    skillsUnknown: false,
    jobsStale: false,
    jobs: [RIFTER_JOB, MERLIN_JOB_UNNAMED_PROJECT, KESTREL_JOB_WITHOUT_PROJECT],
    freeSlots: [],
  }],
  timeline: [RIFTER_JOB, MERLIN_JOB_UNNAMED_PROJECT, KESTREL_JOB_WITHOUT_PROJECT].map(timelineJob),
  skillsMayBeStale: false,
  unknownSkillsCharacterCount: 0,
}

async function mountSlotsSection(): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/industry/slots') return Promise.resolve(SLOTS)
    return new Promise(() => {})
  })
  setActivePinia(createPinia())
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/industry/projects/:id', name: 'industry-project', component: EmptyView }],
  })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(IndustrySlotsSection, { global: { plugins: [i18n, router] } })
  await flushPromises()
  return wrapper
}

function linksOf(wrapper: VueWrapper, selector: string): { href: string | undefined; text: string }[] {
  return wrapper.findAll(selector).map(link => ({ href: link.attributes('href'), text: link.text() }))
}

function tooltipsOf(wrapper: VueWrapper, selector: string): (string | undefined)[] {
  return wrapper.findAll(selector).map(link => link.attributes('title'))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('IndustrySlotsSection job to project links', () => {
  it('links a job matched to a project to that project, in the job table and in the timeline tooltip', async () => {
    const wrapper = await mountSlotsSection()

    const expectedFirstLink = { href: `/industry/projects/${RIFTER_PROJECT_ID}`, text: RIFTER_PROJECT_NAME }
    expect({
      table: linksOf(wrapper, TABLE_LINK)[0],
      timeline: linksOf(wrapper, TIMELINE_LINK)[0],
      fullNameOnTableLinkTooltip: tooltipsOf(wrapper, TABLE_LINK)[0],
    }).toEqual({ table: expectedFirstLink, timeline: expectedFirstLink, fullNameOnTableLinkTooltip: RIFTER_PROJECT_NAME })
  })

  it('labels the link of an unnamed project with the fallback and shows no link for a job without project', async () => {
    const wrapper = await mountSlotsSection()

    const fallbackLabel = (en.industry.slots as Record<string, unknown>).projectLinkFallback
    const expectedLinks = [
      { href: `/industry/projects/${RIFTER_PROJECT_ID}`, text: RIFTER_PROJECT_NAME },
      { href: `/industry/projects/${MERLIN_PROJECT_ID}`, text: fallbackLabel },
    ]
    expect({
      table: linksOf(wrapper, TABLE_LINK),
      timeline: linksOf(wrapper, TIMELINE_LINK),
    }).toEqual({ table: expectedLinks, timeline: expectedLinks })
  })
})
