import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContainerBanner from '@/components/group-industry/ContainerBanner.vue'
import JobsTable from '@/components/group-industry/JobsTable.vue'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, ContainerVerification, ContainerVerificationStatus, GroupProject } from '@/stores/group-industry/types'

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
  }
}

function i18n() {
  return createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
}

function prepareStore(verifications: ContainerVerification[]) {
  setActivePinia(createPinia())
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = { id: PROJECT_ID, name: 'Fernite run', status: 'in_progress', myRole: 'owner', items: [] } as unknown as GroupProject
  projectStore.bomItems = [jobLine(FUEL_BLOCK), jobLine(FERNITE)]
  projectStore.containerVerification = verifications
}

describe('Container check of the intermediates', () => {
  it('shows the state of the container on a component job line', () => {
    prepareStore([verification(FUEL_BLOCK, 'partial', 30)])

    const statuses = mount(JobsTable, { global: { plugins: [i18n()] } }).findAll(CONTAINER_STATUS).map(status => status.text())

    expect(statuses).toEqual(['Partial: 30 / 80'])
  })

  it('shows a verified and a missing intermediate with their own wording', () => {
    prepareStore([verification(FUEL_BLOCK, 'missing', 0)])
    const missing = mount(JobsTable, { global: { plugins: [i18n()] } }).findAll(CONTAINER_STATUS).map(status => status.text())
    prepareStore([verification(FUEL_BLOCK, 'verified', 80)])
    const verified = mount(JobsTable, { global: { plugins: [i18n()] } }).findAll(CONTAINER_STATUS).map(status => status.text())

    expect([missing, verified]).toEqual([['Missing from container'], ['Verified: 80 / 80']])
  })

  it('shows no container state on a final job line or when nothing was verified', () => {
    prepareStore([verification(FERNITE, 'missing', 0)])
    const withFinalOnly = mount(JobsTable, { global: { plugins: [i18n()] } }).findAll(CONTAINER_STATUS)
    prepareStore([])
    const withoutVerification = mount(JobsTable, { global: { plugins: [i18n()] } }).findAll(CONTAINER_STATUS)

    expect([withFinalOnly.length, withoutVerification.length]).toEqual([0, 0])
  })

  it('lists in the banner an approved intermediate that is missing from the container', () => {
    prepareStore([verification(FUEL_BLOCK, 'partial', 30)])

    const text = mount(ContainerBanner, {
      props: { containerName: 'Build', projectId: PROJECT_ID, canVerify: false },
      global: { plugins: [i18n()] },
    }).text().replace(/\s+/g, ' ')

    expect(text).toContain('Hydrogen Fuel Block: 30 / 80 in the container, 80 approved')
  })
})
