import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { i18n } from '@/i18n'
import { useProjectsStore } from '@/stores/industry/projects'
import { useStepsStore } from '@/stores/industry/steps'
import { messageOf, TEST_LOCALES, type TestLocale } from '../localeTexts'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-222'
const STEP_ID = 'step-222'
const MATCH_ID = 'match-222'
const ESI_JOB_ID = 222_001
const RIFTER_TYPE_ID = 587
const GATEWAY_TIMEOUT = 'Gateway timeout (fictif)'
const ESI_ERROR_MESSAGE = 'ESI error limit reached (fictif)'

type ProjectsStore = ReturnType<typeof useProjectsStore>
type StepsStore = ReturnType<typeof useStepsStore>

const PROJECT_FAILURES: Array<[string, string, (store: ProjectsStore) => Promise<unknown>]> = [
  ['fetchProjects', 'fetchProjects', store => store.fetchProjects()],
  ['fetchProject', 'fetchProject', store => store.fetchProject(PROJECT_ID)],
  ['createProject', 'createProject', store => store.createProject(RIFTER_TYPE_ID, 1, 10)],
  ['updateProject', 'updateProject', store => store.updateProject(PROJECT_ID, { name: 'Renamed' })],
  ['deleteProject', 'deleteProject', store => store.deleteProject(PROJECT_ID)],
  ['matchJobs', 'matchJobs', store => store.matchJobs(PROJECT_ID)],
  ['fetchShoppingList', 'fetchShoppingList', store => store.fetchShoppingList(PROJECT_ID)],
  ['fetchBpcKit', 'fetchBpcKit', store => store.fetchBpcKit(PROJECT_ID)],
]

const STEP_FAILURES: Array<[string, string, (store: StepsStore) => Promise<unknown>]> = [
  ['toggleStepPurchased', 'updateStep', store => store.toggleStepPurchased(PROJECT_ID, STEP_ID, true)],
  ['toggleStepInStock', 'updateStep', store => store.toggleStepInStock(PROJECT_ID, STEP_ID, 1)],
  ['updateStep', 'updateStep', store => store.updateStep(PROJECT_ID, STEP_ID, { meLevel: 5 })],
  ['createStep', 'createStep', store => store.createStep(PROJECT_ID, RIFTER_TYPE_ID, 1)],
  ['addChildJob', 'addChildJob', store => store.addChildJob(PROJECT_ID, null, STEP_ID, 1)],
  ['deleteStep', 'deleteStep', store => store.deleteStep(PROJECT_ID, STEP_ID)],
  ['splitStep', 'splitStep', store => store.splitStep(PROJECT_ID, STEP_ID, 2)],
  ['mergeSteps', 'mergeSteps', store => store.mergeSteps(PROJECT_ID, STEP_ID)],
  ['fetchAvailableJobs', 'fetchEsiJobs', store => store.fetchAvailableJobs(PROJECT_ID)],
  ['linkJob', 'linkJob', store => store.linkJob(PROJECT_ID, STEP_ID, ESI_JOB_ID)],
  ['unlinkJob', 'unlinkJob', store => store.unlinkJob(PROJECT_ID, MATCH_ID)],
]

async function ignoringFailure(action: Promise<unknown>): Promise<void> {
  await action.catch(() => undefined)
}

function failApiWith(reason: unknown): void {
  vi.mocked(apiRequest).mockRejectedValue(reason)
}

function useLocale(locale: TestLocale): void {
  i18n.global.locale.value = locale
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  useLocale('en')
})

describe('industry projects store failure fallbacks', () => {
  it.each(TEST_LOCALES.flatMap(locale => PROJECT_FAILURES.map(([action, key, run]) => [locale, action, key, run] as const)))(
    'reports the %s fallback of %s when the failure carries no message',
    async (locale, _action, key, run) => {
      useLocale(locale)
      failApiWith(GATEWAY_TIMEOUT)
      const store = useProjectsStore()

      await ignoringFailure(run(store))

      expect(store.error).toBe(messageOf(locale, `industry.errors.${key}`))
    },
  )

  it.each(TEST_LOCALES)('reports the %s fallback of the profit margin analysis when the failure carries no message', async (locale) => {
    useLocale(locale)
    failApiWith(GATEWAY_TIMEOUT)
    const store = useProjectsStore()

    await ignoringFailure(store.analyzeMargin(RIFTER_TYPE_ID))

    expect(store.marginError).toBe(messageOf(locale, 'industry.errors.analyzeProfitMargin'))
    expect(store.error).toBeNull()
  })

  it.each(TEST_LOCALES)('keeps the message of the failure instead of the %s fallback', async (locale) => {
    useLocale(locale)
    failApiWith(new Error(ESI_ERROR_MESSAGE))
    const store = useProjectsStore()

    await ignoringFailure(store.fetchProjects())

    expect(store.error).toBe(ESI_ERROR_MESSAGE)
  })
})

describe('industry steps store failure fallbacks', () => {
  it.each(TEST_LOCALES.flatMap(locale => STEP_FAILURES.map(([action, key, run]) => [locale, action, key, run] as const)))(
    'reports the %s fallback of %s when the failure carries no message',
    async (locale, _action, key, run) => {
      useLocale(locale)
      failApiWith(GATEWAY_TIMEOUT)
      const projectsStore = useProjectsStore()

      await ignoringFailure(run(useStepsStore()))

      expect(projectsStore.error).toBe(messageOf(locale, `industry.errors.${key}`))
    },
  )

  it.each(TEST_LOCALES)('keeps the message of the failure instead of the %s fallback', async (locale) => {
    useLocale(locale)
    failApiWith(new Error(ESI_ERROR_MESSAGE))
    const projectsStore = useProjectsStore()

    await ignoringFailure(useStepsStore().createStep(PROJECT_ID, RIFTER_TYPE_ID, 1))

    expect(projectsStore.error).toBe(ESI_ERROR_MESSAGE)
  })
})
