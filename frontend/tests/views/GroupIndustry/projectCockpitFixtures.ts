import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import GroupIndustryDetail from '@/views/GroupIndustry/GroupIndustryDetail.vue'
import { apiRequest } from '@/services/api'
import type {
  BomItem,
  GroupMemberRole,
  GroupProject,
  GroupProjectStatus,
  ProjectCockpit,
  ProjectNextAction,
  ProjectPhase,
  ProjectPhaseKey,
  ProjectPhaseState,
} from '@/stores/group-industry/types'
import type { TestLocale } from '../../localeTexts'

export const PROJECT_ID = 'project-nyx-batch'
export const PROJECT_PATH = `/industry/projects/${PROJECT_ID}`
export const COCKPIT_PATH = `${PROJECT_PATH}/cockpit`
export const COCKPIT_RAIL = '[data-testid="cockpit-rail"]'
export const NEXT_ACTION_CARD = '[data-testid="next-action-card"]'
export const NEXT_ACTION_TITLE = '[data-testid="next-action-title"]'
export const NEXT_ACTION_BUTTON = '[data-testid="next-action-button"]'

export const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({
  params: { id: PROJECT_ID },
  query: {},
})

export const PHASE_KEYS: ProjectPhaseKey[] = ['prepare', 'supply', 'produce', 'sell', 'close']

export function phaseSelector(key: ProjectPhaseKey): string {
  return `[data-testid="cockpit-phase-${key}"]`
}

export function tabSelector(key: string): string {
  return `[data-testid="project-tab-${key}"]`
}

export function nyxProject(status: GroupProjectStatus, myRole: GroupMemberRole = 'owner', overrides: Partial<GroupProject> = {}): GroupProject {
  return {
    id: PROJECT_ID,
    name: 'Nyx batch',
    status,
    shortLinkCode: 'nyx731',
    containerName: null,
    ownerCharacterName: 'Selene Vance',
    ownerCorporationId: 98_000_007,
    membersCount: 1,
    items: [{ typeId: 23_913, typeName: 'Nyx', meLevel: 8, teLevel: 16, runs: 2 }],
    totalBomValue: 41_000_000_000,
    fulfillmentPercent: 40,
    approvedContributionValue: 16_400_000_000,
    brokerFeePercent: 2.8,
    salesTaxPercent: 3.4,
    lineRentalRatesOverride: null,
    blacklistGroupIds: [],
    blacklistTypeIds: [],
    createdAt: '2026-11-02T08:30:00+00:00',
    myRole,
    myStatus: 'accepted',
    private: false,
    notes: null,
    acceptsOutsideFavoriteSystem: false,
    minimumOutsideSaving: 5,
    sellStructureId: null,
    sellStructureName: null,
    ...overrides,
  }
}

export function bomLine(overrides: Partial<BomItem> & Pick<BomItem, 'id' | 'typeId' | 'typeName'>): BomItem {
  return {
    requiredQuantity: 1000,
    fulfilledQuantity: 0,
    remainingQuantity: 1000,
    fulfillmentPercent: 0,
    estimatedPrice: 5,
    estimatedTotal: 5000,
    isJob: false,
    jobGroup: null,
    activityType: null,
    parentTypeId: null,
    meLevel: null,
    teLevel: null,
    runs: null,
    isFulfilled: false,
    jobMatches: [],
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
    ...overrides,
  }
}

export function phase(
  key: ProjectPhaseKey,
  state: ProjectPhaseState,
  done: number,
  total: number,
  blockedReason: ProjectPhase['blockedReason'] = null,
): ProjectPhase {
  return { key, state, counter: { done, total }, blockedReason }
}

export function supplyRail(): ProjectPhase[] {
  return [
    phase('prepare', 'done', 1, 1),
    phase('supply', 'current', 2, 5),
    phase('produce', 'upcoming', 0, 6),
    phase('sell', 'upcoming', 0, 2),
    phase('close', 'upcoming', 0, 1),
  ]
}

export function nextAction(overrides: Partial<ProjectNextAction> & Pick<ProjectNextAction, 'code'>): ProjectNextAction {
  return {
    blocked: false,
    tab: null,
    targetStatus: null,
    bomLineId: null,
    typeId: null,
    typeName: null,
    quantity: null,
    ...overrides,
  }
}

export function cockpitOf(status: GroupProjectStatus, action: ProjectNextAction, phases: ProjectPhase[] = supplyRail()): ProjectCockpit {
  return { projectId: PROJECT_ID, status, phases, nextAction: action }
}

export type CockpitAnswers = {
  project: GroupProject
  bom?: BomItem[]
  cockpit?: ProjectCockpit
  cockpitFailure?: string
}

export function answerApi({ project, bom = [], cockpit, cockpitFailure }: CockpitAnswers): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (options?.method === 'PATCH') return { ...project, ...JSON.parse(String(options.body)) }
    if (endpoint === PROJECT_PATH) return project
    if (endpoint === `${PROJECT_PATH}/bom`) return bom
    if (endpoint === COCKPIT_PATH) {
      if (cockpitFailure !== undefined) throw new Error(cockpitFailure)
      return cockpit
    }
    return []
  })
}

export function callsTo(endpoint: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(([calledEndpoint]) => calledEndpoint === endpoint).length
}

export function sentPatchBodies(): unknown[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(([endpoint, options]) => endpoint === PROJECT_PATH && options?.method === 'PATCH')
    .map(([, options]) => JSON.parse(String(options?.body)))
}

export async function mountCockpitDetail(answers: CockpitAnswers, locale: TestLocale = 'en'): Promise<VueWrapper> {
  answerApi(answers)
  const wrapper = mount(GroupIndustryDetail, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        teleport: true,
        BomTable: true,
        JobsTable: true,
        ContainerBanner: true,
        ProjectCourier: true,
        ContributeModal: true,
        ContributionTable: true,
        SalesTable: true,
        SaleSuggestionsPanel: true,
        FinancialSummary: true,
        DistributionTable: true,
        MemberTable: true,
        PendingRequestsTable: true,
        ShareLinkCard: true,
        LoadingSpinner: true,
        ConfirmModal: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

export function normalized(text: string): string {
  return text.replace(/\s+/g, ' ').trim()
}

export function textOf(wrapper: VueWrapper, selector: string): string {
  const found = wrapper.find(selector)
  return found.exists() ? normalized(found.text()) : ''
}

export function attributeOf(wrapper: VueWrapper, selector: string, attribute: string): string | undefined {
  const found = wrapper.find(selector)
  return found.exists() ? found.attributes(attribute) : undefined
}
