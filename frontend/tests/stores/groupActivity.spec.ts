import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupActivityStore } from '@/stores/group-industry/activity'
import type { ProjectActivity, ProjectActivityEvent, ProjectRunningJob } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const NYX_PROJECT_ID = 'project-nyx-batch'
const AVATAR_PROJECT_ID = 'project-avatar-batch'

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
const NYX_JOBS: ProjectRunningJob = {
  characterName: 'Selene Vance',
  typeId: 23_913,
  typeName: 'Nyx',
  jobCount: 2,
  runs: 20,
  startedAt: '2026-11-09T08:00:00+00:00',
  endsAt: '2026-11-09T12:00:00+00:00',
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

function activity(events: ProjectActivityEvent[], runningJobs: ProjectRunningJob[]): ProjectActivity {
  return { events, runningJobs }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group activity store', () => {
  it('reads the activity of the project and keeps its events and running jobs in the order of the response', async () => {
    vi.mocked(apiRequest).mockResolvedValue(activity([FEES_UPDATED, MEMBER_ACCEPTED], [NYX_JOBS, AVATAR_JOBS]))
    const store = useGroupActivityStore()

    await store.fetchActivity(NYX_PROJECT_ID)

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(`/industry/projects/${NYX_PROJECT_ID}/activity`)
    expect({
      eventIds: store.events.map(event => event.id),
      runningJobTypes: store.runningJobs.map(job => job.typeName),
      loading: store.loading,
      error: store.error,
    }).toEqual({
      eventIds: ['event-fees', 'event-member'],
      runningJobTypes: ['Nyx', 'Avatar'],
      loading: false,
      error: null,
    })
  })

  it('keeps the error message and an empty state when the read fails', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Activity unavailable: access refused'))
    const store = useGroupActivityStore()

    await store.fetchActivity(NYX_PROJECT_ID)

    expect({ events: store.events, runningJobs: store.runningJobs, loading: store.loading, error: store.error }).toEqual({
      events: [],
      runningJobs: [],
      loading: false,
      error: 'Activity unavailable: access refused',
    })
  })

  it('drops the state of the previous project and ignores its late response when the project changes', async () => {
    let answerNyx: (value: ProjectActivity) => void = () => {}
    vi.mocked(apiRequest)
      .mockImplementationOnce(() => new Promise<ProjectActivity>((resolve) => { answerNyx = resolve }) as never)
      .mockResolvedValueOnce(activity([MEMBER_ACCEPTED], [AVATAR_JOBS]) as never)
    const store = useGroupActivityStore()

    const nyxRead = store.fetchActivity(NYX_PROJECT_ID)
    await store.fetchActivity(AVATAR_PROJECT_ID)
    answerNyx(activity([FEES_UPDATED], [NYX_JOBS]))
    await nyxRead

    expect({
      eventIds: store.events.map(event => event.id),
      runningJobTypes: store.runningJobs.map(job => job.typeName),
    }).toEqual({ eventIds: ['event-member'], runningJobTypes: ['Avatar'] })
  })
})
