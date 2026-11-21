import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type {
  BomItem,
  ChainActivity,
  ChainNode,
  ChainStage,
  ChainStatus,
  ContainerVerification,
  GroupMemberRole,
  GroupProject,
  JobGroup,
} from '@/stores/group-industry/types'
import type { TestLocale } from './localeTexts'
import { chainNode } from './productionChainNodes'

export const DEFAULT_PROJECT_ID = 'project-production-rows'

const STAGE_OF_JOB_GROUP: Record<JobGroup, ChainStage> = {
  blueprint: 'blueprint',
  component: 'component',
  final: 'product',
}

export type ProductionRowsOptions = {
  lines: BomItem[]
  projectId?: string
  role?: GroupMemberRole
  solo?: boolean
  locale?: TestLocale
  statuses?: Record<string, ChainStatus>
  nodes?: ChainNode[]
  containerVerification?: ContainerVerification[]
  answers?: Record<string, unknown>
}

export function chainNodeOfLine(line: BomItem, overrides: Partial<ChainNode> = {}): ChainNode {
  if (line.jobGroup === null) throw new Error(`line ${line.id} is not a job line`)
  return chainNode({
    id: line.id,
    stage: STAGE_OF_JOB_GROUP[line.jobGroup],
    typeId: line.typeId,
    typeName: line.typeName,
    activity: line.activityType as ChainActivity | null,
    runs: line.runs,
    meLevel: line.meLevel,
    teLevel: line.teLevel,
    requiredQuantity: line.requiredQuantity,
    fulfilledQuantity: line.fulfilledQuantity,
    level: 1,
    status: 'todo',
    ...overrides,
  })
}

export async function mountProductionJobRows(options: ProductionRowsOptions): Promise<VueWrapper> {
  const projectId = options.projectId ?? DEFAULT_PROJECT_ID
  const role = options.role ?? 'owner'
  const nodes = options.nodes ?? options.lines
    .filter(line => line.jobGroup !== null)
    .map(line => chainNodeOfLine(line, { status: options.statuses?.[line.id] ?? 'todo' }))
  const base = `/industry/projects/${projectId}`
  const answers: Record<string, unknown> = {
    [`${base}/chain`]: { id: projectId, nodes },
    [`${base}/reactions`]: { id: projectId, steps: [] },
    [`${base}/bom`]: options.lines,
    ...options.answers,
  }
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => (endpoint in answers ? answers[endpoint] : {})) as never)

  setActivePinia(createPinia())
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = { id: projectId, name: 'Production rows', status: 'in_progress', myRole: role, solo: options.solo ?? false, items: [] } as unknown as GroupProject
  projectStore.bomItems = options.lines
  projectStore.containerVerification = options.containerVerification ?? []

  const wrapper = mount(ProductionChain, {
    props: { projectId, canMatchJobs: role === 'owner', canLinkJobs: true, canMark: false },
    global: {
      plugins: [createI18n({ legacy: false, locale: options.locale ?? 'en', messages: { en, fr } })],
      stubs: { teleport: true },
    },
  })
  await flushPromises()
  await wrapper.get('[data-testid="chain-view-tree"]').trigger('click')
  return wrapper
}

export type FoundElement = Omit<DOMWrapper<Element>, 'exists'>

export function rowOfLine(wrapper: VueWrapper, lineId: string): FoundElement {
  return wrapper.get(`[data-testid="chain-row-${lineId}"]`)
}

export function statusLabelOfRow(wrapper: VueWrapper, lineId: string): string {
  return rowOfLine(wrapper, lineId).get('[data-testid="chain-status"]').text()
}

export function callsTo(endpoint: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(([calledEndpoint]) => calledEndpoint === endpoint).length
}
