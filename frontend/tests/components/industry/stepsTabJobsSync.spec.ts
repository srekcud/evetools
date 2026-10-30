import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StepsTab from '@/components/industry/StepsTab.vue'
import { useProjectsStore } from '@/stores/industry/projects'
import { useStepsStore } from '@/stores/industry/steps'
import { useSyncStore, type SyncProgress, type SyncStatus } from '@/stores/sync'
import type { CharacterSyncFailure, IndustryProject, IndustryProjectStep } from '@/stores/industry/types'

const PROJECT_ID = 'project-215'
const JOBS_SYNC_ERROR_BANNER = '[data-testid="jobs-sync-error"]'
const REGENERATION_ERROR_BANNER = '[data-testid="steps-regeneration-error"]'
const EmptyView = { render: () => null }

const CORMORANT_STEP: IndustryProjectStep = {
  id: 'step-cormorant',
  blueprintTypeId: 16239,
  productTypeId: 16238,
  productTypeName: 'Cormorant',
  quantity: 6,
  runs: 6,
  depth: 0,
  activityType: 'manufacturing',
  runsFixed: false,
  sortOrder: 0,
  purchased: false,
  inStockQuantity: 0,
  meLevel: 8,
  teLevel: 16,
  jobMatchMode: 'auto',
  structureConfigId: null,
  structureConfigName: null,
  structureMaterialBonus: null,
  structureTimeBonus: null,
  favoriteSystemWithoutSuitableStructure: false,
  timePerRun: null,
  recommendedCharacterName: null,
  splitGroupId: null,
  splitIndex: 0,
  totalGroupRuns: null,
  facilityInfoType: null,
  actualFacilityName: null,
  bestStructureName: null,
  bestMaterialBonus: null,
  jobMatches: [],
  jobsCost: null,
  purchases: [],
  purchasesCost: null,
  purchasedQuantity: 0,
  inStock: false,
  esiJobId: null,
  esiJobCost: null,
  esiJobStatus: null,
  esiJobEndDate: null,
  esiJobRuns: null,
  esiJobCharacterName: null,
  esiJobsCount: null,
  esiJobsTotalRuns: null,
  esiJobsActiveRuns: null,
  esiJobsDeliveredRuns: null,
  manualJobData: false,
  recommendedStructureName: null,
  structureBonus: null,
  similarJobs: [],
}

const CORMORANT_PROJECT: IndustryProject = {
  id: PROJECT_ID,
  productTypeName: 'Cormorant',
  productTypeId: 16238,
  displayName: null,
  runs: 6,
  meLevel: 8,
  teLevel: 16,
  maxJobDurationDays: 3,
  status: 'active',
  personalUse: false,
  isT2: false,
  bpoCost: null,
  materialCost: null,
  transportCost: null,
  jobsCost: null,
  taxAmount: null,
  sellPrice: null,
  totalCost: null,
  estimatedJobCost: null,
  estimatedSellPrice: null,
  estimatedSellPriceSource: null,
  estimatedTaxAmount: null,
  estimatedMaterialCost: null,
  profit: null,
  profitPercent: null,
  notes: null,
  createdAt: '2026-10-29T07:30:00+00:00',
  completedAt: null,
  jobsStartDate: null,
  steps: [CORMORANT_STEP],
  tree: null,
  failedProducts: [],
}

function syncEvent(syncType: string, status: SyncStatus, progress: number | null, message: string, data: Record<string, unknown> | null, timestamp: string): SyncProgress {
  return { syncType, status, progress, message, data, timestamp }
}

const LAST_JOBS_SYNC_FAILURES_BANNER = '[data-testid="last-jobs-sync-failures"]'
const MATCH_JOBS_FAILURES_BANNER = '[data-testid="jobs-sync-failures"]'
const PILOT_FIVE_ESI_ERROR: CharacterSyncFailure = { characterName: 'Pilot Five', reason: 'esi_error', statusCode: 403 }
const USER_JOBS_SYNC_END_WITH_PILOT_FIVE_NOT_SYNCED = syncEvent('industry-jobs', 'completed', 100, '2 active jobs, 1 ready to deliver, 1 characters not synced', { total: 3, active: 2, ready: 1, failedCharacters: [PILOT_FIVE_ESI_ERROR] }, '2026-10-30T10:00:05+00:00')

const PILOT_ONE_JOBS_SYNC_END = syncEvent('industry-jobs', 'completed', 100, '2 active jobs, 1 ready to deliver', { total: 3, active: 2, ready: 1 }, '2026-10-29T11:00:05+00:00')
const NOTIFICATION = 'notification' as string as SyncStatus

function jobsSyncError(message: string, timestamp: string): SyncProgress {
  return syncEvent('industry-jobs', 'error', null, message, null, timestamp)
}

async function receive(message: SyncProgress) {
  const syncStore = useSyncStore()
  syncStore.syncStatus = { ...syncStore.syncStatus, [message.syncType]: message }
  await flushPromises()
}

function mountStepsTab(locale: 'en' | 'fr' = 'en') {
  const projectsStore = useProjectsStore()
  projectsStore.currentProject = CORMORANT_PROJECT
  const matchJobs = vi.spyOn(projectsStore, 'matchJobs').mockReturnValue(new Promise<never>(() => {}))
  const fetchProject = vi.spyOn(projectsStore, 'fetchProject').mockResolvedValue(undefined)
  const fetchAvailableJobs = vi.spyOn(useStepsStore(), 'fetchAvailableJobs').mockResolvedValue(undefined)
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: EmptyView },
      { path: '/characters', component: EmptyView },
    ],
  })
  const wrapper = mount(StepsTab, {
    props: { projectId: PROJECT_ID },
    global: { plugins: [i18n, router], stubs: { StepHierarchyTree: true } },
  })
  return { wrapper, matchJobs, fetchProject, fetchAvailableJobs }
}

function buttonLabelled(wrapper: VueWrapper, label: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === label)
  if (!button) throw new Error(`No button labelled ${label}`)
  return button
}

async function openEsiJobsPanel(wrapper: VueWrapper) {
  await buttonLabelled(wrapper, en.industry.stepsTab.esiJobs).trigger('click')
  await flushPromises()
}

function bannerLines(wrapper: VueWrapper, banner: string): string[] {
  return wrapper.find(banner).findAll('li').map(line => line.text())
}

async function closeBanner(wrapper: VueWrapper, banner: string) {
  const closeButton = wrapper.find(banner).findAll('button').find(button => button.attributes('aria-label') === en.common.actions.close)
  if (!closeButton) throw new Error('No close button with an accessible name')
  await closeButton.trigger('click')
  await flushPromises()
}

function regenerationError(message: string, timestamp: string): SyncProgress {
  return syncEvent('industry-project', 'error', null, message, null, timestamp)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('StepsTab on jobs sync events (issue 215)', () => {
  it('reloads the project but not the available jobs on a jobs sync end while the ESI jobs panel is closed', async () => {
    const { fetchProject, fetchAvailableJobs } = mountStepsTab()
    await flushPromises()

    await receive(PILOT_ONE_JOBS_SYNC_END)

    expect(fetchProject).toHaveBeenCalledTimes(1)
    expect(fetchProject).toHaveBeenCalledWith(PROJECT_ID)
    expect(fetchAvailableJobs).not.toHaveBeenCalled()
  })

  it('reloads the project and the available jobs on a jobs sync end while the ESI jobs panel is open', async () => {
    const { wrapper, fetchProject, fetchAvailableJobs } = mountStepsTab()
    await openEsiJobsPanel(wrapper)

    expect(fetchAvailableJobs).toHaveBeenCalledTimes(1)

    await receive(PILOT_ONE_JOBS_SYNC_END)

    expect(fetchProject).toHaveBeenCalledTimes(1)
    expect(fetchProject).toHaveBeenCalledWith(PROJECT_ID)
    expect(fetchAvailableJobs).toHaveBeenCalledTimes(2)
    expect(fetchAvailableJobs).toHaveBeenLastCalledWith(PROJECT_ID)
  })

  it('ignores the jobs sync end and error received while matching the jobs', async () => {
    const { wrapper, matchJobs, fetchProject, fetchAvailableJobs } = mountStepsTab()
    await openEsiJobsPanel(wrapper)
    await buttonLabelled(wrapper, en.industry.stepsTab.syncJobs).trigger('click')
    await flushPromises()

    expect(matchJobs).toHaveBeenCalledTimes(1)

    await receive(PILOT_ONE_JOBS_SYNC_END)
    await receive(jobsSyncError('boom A', '2026-10-29T11:10:00+00:00'))

    expect(fetchProject).not.toHaveBeenCalled()
    expect(fetchAvailableJobs).toHaveBeenCalledTimes(1)
    expect(wrapper.find(JOBS_SYNC_ERROR_BANNER).exists()).toBe(false)
  })

  it('shows a jobs sync error in a banner and keeps the steps', async () => {
    const { wrapper, fetchProject } = mountStepsTab()
    await flushPromises()

    await receive(jobsSyncError('boom A', '2026-10-29T11:20:00+00:00'))

    expect(wrapper.findAll(JOBS_SYNC_ERROR_BANNER)).toHaveLength(1)
    expect(wrapper.find(JOBS_SYNC_ERROR_BANNER).text()).toContain('Industry jobs sync failed (1 error), the jobs shown may be out of date:')
    expect(bannerLines(wrapper, JOBS_SYNC_ERROR_BANNER)).toEqual(['boom A'])
    expect(wrapper.findAll('.step-row')).toHaveLength(1)
    expect(fetchProject).not.toHaveBeenCalled()
  })

  it('shows a steps regeneration error in its own banner without reloading the project', async () => {
    const { wrapper, fetchProject } = mountStepsTab()
    await flushPromises()

    await receive(syncEvent('industry-project', 'error', null, 'Blueprint missing (fictif)', null, '2026-10-29T11:30:00+00:00'))

    expect(wrapper.findAll(REGENERATION_ERROR_BANNER)).toHaveLength(1)
    expect(wrapper.find(REGENERATION_ERROR_BANNER).text()).toContain('Steps regeneration failed:')
    expect(bannerLines(wrapper, REGENERATION_ERROR_BANNER)).toEqual(['Blueprint missing (fictif)'])
    expect(wrapper.find(JOBS_SYNC_ERROR_BANNER).exists()).toBe(false)
    expect(fetchProject).not.toHaveBeenCalled()
  })

  it('titles the steps regeneration error banner in french and closes it from its accessible close button', async () => {
    const { wrapper } = mountStepsTab('fr')
    await flushPromises()

    await receive(syncEvent('industry-project', 'error', null, 'Tree build failed (fictif)', null, '2026-10-29T11:35:00+00:00'))

    expect(wrapper.findAll(REGENERATION_ERROR_BANNER)).toHaveLength(1)
    expect(wrapper.find(REGENERATION_ERROR_BANNER).text()).toContain('Échec de la régénération des étapes :')
    const closeButton = wrapper.find(REGENERATION_ERROR_BANNER).findAll('button').find(button => button.attributes('aria-label') === 'Fermer')
    if (!closeButton) throw new Error('No close button with an accessible name')
    await closeButton.trigger('click')

    expect(wrapper.find(REGENERATION_ERROR_BANNER).exists()).toBe(false)
  })

  it('reloads the project once on a steps regeneration end', async () => {
    const { fetchProject } = mountStepsTab()
    await flushPromises()

    await receive(syncEvent('industry-project', 'completed', 100, 'Steps regenerated', { projectId: PROJECT_ID, stepsCount: 1 }, '2026-10-29T11:40:00+00:00'))

    expect(fetchProject).toHaveBeenCalledTimes(1)
    expect(fetchProject).toHaveBeenCalledWith(PROJECT_ID)
  })

  it('reloads the project on a jobs sync end received after the jobs match resolved', async () => {
    const { wrapper, matchJobs, fetchProject } = mountStepsTab()
    matchJobs.mockResolvedValue([])
    await openEsiJobsPanel(wrapper)
    await buttonLabelled(wrapper, en.industry.stepsTab.syncJobs).trigger('click')
    await flushPromises()

    expect(matchJobs).toHaveBeenCalledTimes(1)
    expect(fetchProject).toHaveBeenCalledTimes(1)

    await receive(syncEvent('industry-jobs', 'completed', 100, '4 active jobs, 0 ready to deliver', { total: 4, active: 4, ready: 0 }, '2026-10-29T11:15:00+00:00'))

    expect(fetchProject).toHaveBeenCalledTimes(2)
    expect(fetchProject).toHaveBeenLastCalledWith(PROJECT_ID)
  })

  it('closes the jobs sync error banner', async () => {
    const { wrapper } = mountStepsTab()
    await flushPromises()

    await receive(jobsSyncError('boom E', '2026-10-29T11:25:00+00:00'))

    expect(wrapper.findAll(JOBS_SYNC_ERROR_BANNER)).toHaveLength(1)
    await closeBanner(wrapper, JOBS_SYNC_ERROR_BANNER)

    expect(wrapper.find(JOBS_SYNC_ERROR_BANNER).exists()).toBe(false)
  })

  it('shows the translated fallback for a jobs sync error without message', async () => {
    const { wrapper } = mountStepsTab()
    await flushPromises()

    await receive(jobsSyncError('', '2026-10-29T11:27:00+00:00'))

    expect(wrapper.findAll(JOBS_SYNC_ERROR_BANNER)).toHaveLength(1)
    expect(bannerLines(wrapper, JOBS_SYNC_ERROR_BANNER)).toEqual([en.notifications.syncError])
    expect(bannerLines(wrapper, JOBS_SYNC_ERROR_BANNER)).toEqual(['Sync error'])
  })

  it('shows the translated fallback for a steps regeneration error without message', async () => {
    const { wrapper } = mountStepsTab()
    await flushPromises()

    await receive(regenerationError('', '2026-10-29T11:32:00+00:00'))

    expect(wrapper.findAll(REGENERATION_ERROR_BANNER)).toHaveLength(1)
    expect(bannerLines(wrapper, REGENERATION_ERROR_BANNER)).toEqual([en.notifications.syncError])
    expect(bannerLines(wrapper, REGENERATION_ERROR_BANNER)).toEqual(['Sync error'])
  })

  it('lists two steps regeneration errors and reopens with only the next one after closing', async () => {
    const { wrapper } = mountStepsTab()
    await flushPromises()

    await receive(regenerationError('Blueprint missing (fictif)', '2026-10-29T11:37:00+00:00'))
    await receive(regenerationError('Tree build failed (fictif)', '2026-10-29T11:37:08+00:00'))

    expect(wrapper.findAll(REGENERATION_ERROR_BANNER)).toHaveLength(1)
    expect(bannerLines(wrapper, REGENERATION_ERROR_BANNER)).toEqual(['Blueprint missing (fictif)', 'Tree build failed (fictif)'])

    await closeBanner(wrapper, REGENERATION_ERROR_BANNER)

    expect(wrapper.find(REGENERATION_ERROR_BANNER).exists()).toBe(false)

    await receive(regenerationError('boom B', '2026-10-29T11:38:00+00:00'))

    expect(wrapper.findAll(REGENERATION_ERROR_BANNER)).toHaveLength(1)
    expect(bannerLines(wrapper, REGENERATION_ERROR_BANNER)).toEqual(['boom B'])
  })

  it('reloads nothing on the job completed and project progress notifications', async () => {
    const { fetchProject, fetchAvailableJobs } = mountStepsTab()
    await flushPromises()

    await receive(syncEvent('industry-job-completed', NOTIFICATION, null, 'Cormorant - Manufacturing completed!', { jobId: 215001, activityId: 1 }, '2026-10-29T11:50:00+00:00'))
    await receive(syncEvent('industry-project', NOTIFICATION, null, 'Project "Cormorant": Cormorant completed', { projectId: PROJECT_ID, completedSteps: ['Cormorant'] }, '2026-10-29T11:50:01+00:00'))

    expect(fetchProject).not.toHaveBeenCalled()
    expect(fetchAvailableJobs).not.toHaveBeenCalled()
  })
})

describe('StepsTab on the characters not synced by the last user jobs sync (issue 215)', () => {
  it('names the character not synced by the last jobs sync in its own banner and reloads the project once', async () => {
    const { wrapper, fetchProject } = mountStepsTab()
    await flushPromises()

    await receive(USER_JOBS_SYNC_END_WITH_PILOT_FIVE_NOT_SYNCED)

    expect(wrapper.findAll(LAST_JOBS_SYNC_FAILURES_BANNER)).toHaveLength(1)
    expect(wrapper.find(LAST_JOBS_SYNC_FAILURES_BANNER).text()).toContain('Jobs not synced for 1 character at the last sync, the jobs shown may be out of date:')
    expect(bannerLines(wrapper, LAST_JOBS_SYNC_FAILURES_BANNER)).toEqual(['Pilot Five: ESI error 403'])
    expect(fetchProject).toHaveBeenCalledTimes(1)
    expect(fetchProject).toHaveBeenCalledWith(PROJECT_ID)
    expect(wrapper.find(MATCH_JOBS_FAILURES_BANNER).exists()).toBe(false)
  })

  it('ignores the characters not synced carried by a jobs sync end received while matching the jobs', async () => {
    const { wrapper, matchJobs, fetchProject } = mountStepsTab()
    await openEsiJobsPanel(wrapper)
    await buttonLabelled(wrapper, en.industry.stepsTab.syncJobs).trigger('click')
    await flushPromises()

    expect(matchJobs).toHaveBeenCalledTimes(1)

    await receive(USER_JOBS_SYNC_END_WITH_PILOT_FIVE_NOT_SYNCED)

    expect(wrapper.find(LAST_JOBS_SYNC_FAILURES_BANNER).exists()).toBe(false)
    expect(fetchProject).not.toHaveBeenCalled()
  })
})
