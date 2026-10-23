import { createPinia, setActivePinia } from 'pinia'
import { useProjectsStore } from '@/stores/industry/projects'

const MINIMUM_MAX_JOB_DURATION_DAYS = 0.5
const MAX_JOB_DURATION_DAYS_BELOW_THE_MINIMUM = 0.2
const MAX_JOB_DURATION_DAYS_ABOVE_THE_MINIMUM = 3

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('projects store default max job duration', () => {
  it('raises a default max job duration below half a day to half a day', () => {
    const store = useProjectsStore()

    store.setDefaultMaxJobDurationDays(MAX_JOB_DURATION_DAYS_BELOW_THE_MINIMUM)

    expect(store.defaultMaxJobDurationDays).toBe(MINIMUM_MAX_JOB_DURATION_DAYS)
  })

  it('keeps a default max job duration of at least half a day', () => {
    const store = useProjectsStore()

    store.setDefaultMaxJobDurationDays(MAX_JOB_DURATION_DAYS_ABOVE_THE_MINIMUM)

    expect(store.defaultMaxJobDurationDays).toBe(MAX_JOB_DURATION_DAYS_ABOVE_THE_MINIMUM)
  })
})
