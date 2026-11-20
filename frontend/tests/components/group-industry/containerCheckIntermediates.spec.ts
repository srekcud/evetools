import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContainerBanner from '@/components/group-industry/ContainerBanner.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, ContainerVerification, ContainerVerificationStatus, GroupProject } from '@/stores/group-industry/types'
import { mountProductionJobRows } from '../../productionJobRows'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-fernite-run'
const CONTAINER_STATUS = '[data-testid="container-line-status"]'

type JobLine = { id: string; typeId: number; typeName: string; jobGroup: 'component' | 'final'; requiredQuantity: number; fulfilledQuantity: number }

const FUEL_BLOCK: JobLine = { id: 'job-fuel-block', typeId: 4_246, typeName: 'Hydrogen Fuel Block', jobGroup: 'component', requiredQuantity: 80, fulfilledQuantity: 80 }
const FERNITE: JobLine = { id: 'job-fernite', typeId: 16_673, typeName: 'Fernite Carbide', jobGroup: 'final', requiredQuantity: 10_000, fulfilledQuantity: 0 }

function jobLine(line: JobLine): BomItem {
  return {
    id: line.id,
    typeId: line.typeId,
    typeName: line.typeName,
    requiredQuantity: line.requiredQuantity,
    fulfilledQuantity: line.fulfilledQuantity,
    remainingQuantity: Math.max(0, line.requiredQuantity - line.fulfilledQuantity),
    fulfillmentPercent: 0,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: true,
    jobGroup: line.jobGroup,
    activityType: 'manufacturing',
    parentTypeId: null,
    meLevel: 10,
    teLevel: 20,
    runs: 1,
    isFulfilled: false,
    jobMatches: [],
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    pendingContributions: [],
    reservedQuantity: 0,
    freeQuantity: 0,
  }
}

function verification(line: JobLine, status: ContainerVerificationStatus, containerQuantity: number | null): ContainerVerification {
  return {
    bomItemId: line.id,
    typeId: line.typeId,
    typeName: line.typeName,
    requiredQuantity: line.requiredQuantity,
    containerQuantity,
    status,
    unknownReason: null,
    assetsSyncedAt: null,
    missingContainerNames: [],
  }
}

function i18n() {
  return createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
}

function productionRows(verifications: ContainerVerification[]) {
  return mountProductionJobRows({
    lines: [jobLine(FUEL_BLOCK), jobLine(FERNITE)],
    projectId: PROJECT_ID,
    containerVerification: verifications,
  })
}

function prepareStore(verifications: ContainerVerification[]) {
  setActivePinia(createPinia())
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = { id: PROJECT_ID, name: 'Fernite run', status: 'in_progress', myRole: 'owner', items: [] } as unknown as GroupProject
  projectStore.bomItems = [jobLine(FUEL_BLOCK), jobLine(FERNITE)]
  projectStore.containerVerification = verifications
}

function statusTexts(wrapper: VueWrapper): string[] {
  return wrapper.findAll(CONTAINER_STATUS).map(status => status.text())
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('Container check of the intermediates', () => {
  it('shows the state of the container on a component job row', async () => {
    const statuses = statusTexts(await productionRows([verification(FUEL_BLOCK, 'partial', 30)]))

    expect(statuses).toEqual(['Partly in container: 30 / 80'])
  })

  it('shows an in-container and a missing intermediate with their own wording', async () => {
    const missing = statusTexts(await productionRows([verification(FUEL_BLOCK, 'missing', 0)]))
    const verified = statusTexts(await productionRows([verification(FUEL_BLOCK, 'verified', 80)]))

    expect([missing, verified]).toEqual([['Missing from container'], ['In container: 80 / 80']])
  })

  it('shows no container state on a final job row or when nothing was verified', async () => {
    const withFinalOnly = statusTexts(await productionRows([verification(FERNITE, 'missing', 0)]))
    const withoutVerification = statusTexts(await productionRows([]))

    expect([withFinalOnly.length, withoutVerification.length]).toEqual([0, 0])
  })

  it('lists in the banner an approved intermediate that is missing from the container', () => {
    prepareStore([verification(FUEL_BLOCK, 'partial', 30)])

    const text = mount(ContainerBanner, {
      props: { containerNames: ['Build'], projectId: PROJECT_ID, canVerify: false },
      global: { plugins: [i18n()] },
    }).text().replace(/\s+/g, ' ')

    expect(text).toContain('Hydrogen Fuel Block: 30 / 80 in the container, 80 approved')
  })
})
