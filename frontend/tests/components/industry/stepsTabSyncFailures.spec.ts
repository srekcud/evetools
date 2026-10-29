import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StepsTab from '@/components/industry/StepsTab.vue'
import { useProjectsStore } from '@/stores/industry/projects'
import { useStepsStore } from '@/stores/industry/steps'
import type { CharacterSyncFailure, IndustryProject, IndustryProjectStep } from '@/stores/industry/types'

const PROJECT_ID = 'project-197'
const BANNER = '[data-testid="jobs-sync-failures"]'
const EmptyView = { render: () => null }

const RIFTER_STEP: IndustryProjectStep = {
  id: 'step-rifter',
  blueprintTypeId: 691,
  productTypeId: 587,
  productTypeName: 'Rifter',
  quantity: 4,
  runs: 4,
  depth: 0,
  activityType: 'manufacturing',
  runsFixed: false,
  sortOrder: 0,
  purchased: false,
  inStockQuantity: 0,
  meLevel: 10,
  teLevel: 20,
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

const RIFTER_PROJECT: IndustryProject = {
  id: PROJECT_ID,
  productTypeName: 'Rifter',
  productTypeId: 587,
  displayName: null,
  runs: 4,
  meLevel: 10,
  teLevel: 20,
  maxJobDurationDays: 2,
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
  createdAt: '2026-10-29T08:00:00+00:00',
  completedAt: null,
  jobsStartDate: null,
  steps: [RIFTER_STEP],
  tree: null,
  failedProducts: [],
}

async function mountAndSyncJobs(failedCharacters: CharacterSyncFailure[], locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  const projectsStore = useProjectsStore()
  projectsStore.currentProject = RIFTER_PROJECT
  vi.spyOn(projectsStore, 'matchJobs').mockResolvedValue(failedCharacters as never)
  vi.spyOn(projectsStore, 'fetchProject').mockResolvedValue(undefined)
  vi.spyOn(useStepsStore(), 'fetchAvailableJobs').mockResolvedValue(undefined)
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
  const labels = (locale === 'en' ? en : fr).industry.stepsTab
  await buttonLabelled(wrapper, labels.esiJobs).trigger('click')
  await flushPromises()
  await buttonLabelled(wrapper, labels.syncJobs).trigger('click')
  await flushPromises()
  return wrapper
}

function buttonLabelled(wrapper: VueWrapper, label: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === label)
  if (!button) throw new Error(`No button labelled ${label}`)
  return button
}

function bannerLines(wrapper: VueWrapper): string[] {
  return wrapper.find(BANNER).findAll('li').map(line => line.text())
}

function reconnectLinkTargets(wrapper: VueWrapper): (string | undefined)[] {
  return wrapper.find(BANNER).findAll('a').filter(link => link.text() === 'Reconnect characters').map(link => link.attributes('href'))
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('StepsTab job sync failures', () => {
  it('names a character whose jobs are forbidden with the status code and keeps the steps', async () => {
    const wrapper = await mountAndSyncJobs([{ characterName: 'Pilot Two', reason: 'esi_error', statusCode: 403 }])

    expect(wrapper.findAll(BANNER)).toHaveLength(1)
    expect(wrapper.find(BANNER).text()).toContain('Jobs not synced for 1 character, matching used the last known jobs:')
    expect(bannerLines(wrapper)).toEqual(['Pilot Two: ESI error 403'])
    expect(wrapper.find(BANNER).findAll('a')).toHaveLength(0)
    expect(wrapper.findAll('.step-row')).toHaveLength(1)
  })

  it('names every failed character with the plural title and a link to reconnect them', async () => {
    const wrapper = await mountAndSyncJobs([
      { characterName: 'Pilot One', reason: 'esi_unavailable', statusCode: 503 },
      { characterName: 'Pilot Two', reason: 'authentication_required', statusCode: null },
    ])

    expect(wrapper.findAll(BANNER)).toHaveLength(1)
    expect(wrapper.find(BANNER).text()).toContain('Jobs not synced for 2 characters, matching used the last known jobs:')
    expect(bannerLines(wrapper)).toEqual([
      'Pilot One: ESI unavailable (maintenance or server error), retry later',
      'Pilot Two: authorization revoked or expired, reconnect the character',
    ])
    expect(reconnectLinkTargets(wrapper)).toEqual(['/characters'])
  })

  it('shows no banner when every character was synced', async () => {
    const wrapper = await mountAndSyncJobs([])

    expect(wrapper.find(BANNER).exists()).toBe(false)
    expect(wrapper.findAll('.step-row')).toHaveLength(1)
  })

  it('removes the banner from its close button', async () => {
    const wrapper = await mountAndSyncJobs([{ characterName: 'Pilot Two', reason: 'esi_error', statusCode: 403 }])

    expect(wrapper.findAll(BANNER)).toHaveLength(1)
    const closeButton = wrapper.find(BANNER).findAll('button').find(button => button.attributes('aria-label') === 'Close')
    if (!closeButton) throw new Error('No close button with an accessible name')
    await closeButton.trigger('click')

    expect(wrapper.find(BANNER).exists()).toBe(false)
  })

  it('names a character without token in french', async () => {
    const wrapper = await mountAndSyncJobs([{ characterName: 'Pilot Three', reason: 'missing_token', statusCode: null }], 'fr')

    expect(wrapper.findAll(BANNER)).toHaveLength(1)
    expect(wrapper.find(BANNER).text()).toContain('Jobs non synchronisés pour 1 personnage, le matching a utilisé les derniers jobs connus :')
    expect(bannerLines(wrapper)).toEqual(['Pilot Three : aucun token ESI, reconnectez le personnage'])
  })

  it('names two failed characters with the french plural title', async () => {
    const wrapper = await mountAndSyncJobs([
      { characterName: 'Pilot One', reason: 'esi_rate_limited', statusCode: 429 },
      { characterName: 'Pilot Three', reason: 'sync_failed', statusCode: null },
    ], 'fr')

    expect(wrapper.find(BANNER).text()).toContain('Jobs non synchronisés pour 2 personnages, le matching a utilisé les derniers jobs connus :')
    expect(bannerLines(wrapper)).toEqual([
      'Pilot One : limite de requêtes ESI atteinte, réessayez plus tard',
      'Pilot Three : échec de la synchronisation',
    ])
  })
})
