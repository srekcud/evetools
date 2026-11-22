import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import IntermediateStockProposal from '@/components/group-industry/IntermediateStockProposal.vue'
import { apiRequest } from '@/services/api'
import type {
  GroupProjectStatus,
  IntermediateStockProposal as IntermediateStockProposalResource,
  IntermediateStockProposalLine,
  IntermediateStockProposalState,
  IntermediateStockUnknownReason,
} from '@/stores/group-industry/types'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const PROJECT_ID = 'project-ferrite-run'
const OTHER_PROJECT_ID = 'project-hecate-batch'
const PROPOSAL_PATH = `/industry/projects/${PROJECT_ID}/intermediate-stock-proposal`
const ROOT = '[data-testid="intermediate-stock-proposal"]'
const BLANK = /[\u00a0\u202f]/g

const HYDROGEN_FUEL_BLOCK: IntermediateStockProposalLine = {
  bomItemId: 'line-hydrogen-fuel-block',
  typeId: 4_246,
  typeName: 'Hydrogen Fuel Block',
  demandQuantity: 110,
  remainingQuantity: 100,
  personalQuantity: 30,
  containerQuantity: 50,
  stockQuantity: 80,
  proposedQuantity: 80,
  stockByStation: [],
  plannedStationName: null,
}
const FERNITE_ALLOY: IntermediateStockProposalLine = {
  bomItemId: 'line-fernite-alloy',
  typeId: 16_656,
  typeName: 'Fernite Alloy',
  demandQuantity: 60,
  remainingQuantity: 60,
  personalQuantity: 45,
  containerQuantity: null,
  stockQuantity: 45,
  proposedQuantity: 45,
  stockByStation: [],
  plannedStationName: null,
}

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

const projectEvents = ref<Record<string, GroupProjectEvent>>({})

function proposal(
  lines: IntermediateStockProposalLine[],
  state: IntermediateStockProposalState = 'ready',
  unknownReason: IntermediateStockUnknownReason | null = null,
): IntermediateStockProposalResource {
  return { id: PROJECT_ID, state, unknownReason, lines }
}

function serveProposals(...responses: IntermediateStockProposalResource[]): void {
  let served = 0
  vi.mocked(apiRequest).mockImplementation(async (path: string) => {
    if (path === PROPOSAL_PATH) {
      const response = responses[Math.min(served, responses.length - 1)]
      served += 1
      return response
    }
    return []
  })
}

async function mountProposal(projectStatus: GroupProjectStatus) {
  setActivePinia(createPinia())
  const syncStore = useSyncStore()
  vi.spyOn(syncStore, 'getGroupProjectEvent').mockImplementation(projectId => projectEvents.value[projectId] ?? null)
  const wrapper = mount(IntermediateStockProposal, { props: { projectId: PROJECT_ID, projectStatus }, global: { plugins: [i18n] } })
  await flushPromises()
  return { wrapper, syncStore }
}

function proposalReads(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([path]) => path === PROPOSAL_PATH).length
}

function contributionSynced(): GroupProjectEvent {
  return { action: 'contribution_synced', data: null, timestamp: '2026-11-21T10:00:00+00:00' }
}

function syncProgress(syncType: string, status: string, data: Record<string, unknown> | null = null) {
  return {
    syncType,
    status: status as never,
    progress: null,
    message: `${syncType} ${status}`,
    data,
    timestamp: '2026-11-21T10:05:00+00:00',
  }
}

function rowExists(wrapper: VueWrapper, line: IntermediateStockProposalLine): boolean {
  return wrapper.find(`[data-testid="intermediate-stock-proposal-row-${line.bomItemId}"]`).exists()
}

function fieldValue(wrapper: VueWrapper, testId: string): string {
  return (wrapper.find(`[data-testid="${testId}"]`).element as HTMLInputElement).value
}

function textOf(wrapper: VueWrapper, testId: string): string {
  const found = wrapper.find(`[data-testid="${testId}"]`)
  return found.exists() ? found.text().replace(BLANK, ' ') : 'absent'
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  projectEvents.value = {}
})

describe('intermediate stock proposal refresh', () => {
  it('reads the proposal again on an event of its project and shows the intermediates that reached the assets', async () => {
    serveProposals(proposal([HYDROGEN_FUEL_BLOCK]), proposal([HYDROGEN_FUEL_BLOCK, FERNITE_ALLOY]))
    const { wrapper } = await mountProposal('draft')

    projectEvents.value = { [OTHER_PROJECT_ID]: contributionSynced() }
    await flushPromises()
    const readsAfterOtherProject = proposalReads()
    const fernitePresentBefore = rowExists(wrapper, FERNITE_ALLOY)
    projectEvents.value = { ...projectEvents.value, [PROJECT_ID]: contributionSynced() }
    await flushPromises()

    expect({ readsAfterOtherProject, fernitePresentBefore, readsAfterOwnEvent: proposalReads(), fernitePresentAfter: rowExists(wrapper, FERNITE_ALLOY) }).toEqual({
      readsAfterOtherProject: 1,
      fernitePresentBefore: false,
      readsAfterOwnEvent: 2,
      fernitePresentAfter: true,
    })
  })

  it('reads the proposal again when a job of its project is delivered and not when a job of another project is', async () => {
    serveProposals(proposal([HYDROGEN_FUEL_BLOCK]))
    const { syncStore } = await mountProposal('in_progress')

    syncStore.syncStatus['industry-project'] = syncProgress('industry-project', 'notification', { projectId: OTHER_PROJECT_ID })
    await flushPromises()
    const readsAfterOtherProject = proposalReads()
    syncStore.syncStatus['industry-project'] = syncProgress('industry-project', 'notification', { projectId: PROJECT_ID })
    await flushPromises()

    expect({ readsAfterOtherProject, readsAfterOwnProject: proposalReads() }).toEqual({
      readsAfterOtherProject: 1,
      readsAfterOwnProject: 2,
    })
  })

  it.each(['character-assets', 'corporation-assets'])('reads the proposal again when the %s sync completes and not while it runs', async syncType => {
    serveProposals(proposal([HYDROGEN_FUEL_BLOCK]))
    const { syncStore } = await mountProposal('in_progress')

    syncStore.syncStatus[syncType] = syncProgress(syncType, 'in_progress')
    await flushPromises()
    const readsWhileRunning = proposalReads()
    syncStore.syncStatus[syncType] = syncProgress(syncType, 'completed')
    await flushPromises()

    expect({ readsWhileRunning, readsAfterCompletion: proposalReads() }).toEqual({
      readsWhileRunning: 1,
      readsAfterCompletion: 2,
    })
  })

  it('keeps the checked state and the edited quantity of a line when the proposal is read again, and lowers a quantity above the new maximum', async () => {
    const hydrogenLessCovered = { ...HYDROGEN_FUEL_BLOCK, proposedQuantity: 60, stockQuantity: 60 }
    serveProposals(proposal([HYDROGEN_FUEL_BLOCK, FERNITE_ALLOY]), proposal([hydrogenLessCovered, FERNITE_ALLOY]))
    const { wrapper } = await mountProposal('draft')
    await wrapper.find(`[data-testid="intermediate-stock-proposal-select-${FERNITE_ALLOY.bomItemId}"]`).setValue(false)
    await wrapper.find(`[data-testid="intermediate-stock-proposal-quantity-${FERNITE_ALLOY.bomItemId}"]`).setValue('20')

    projectEvents.value = { [PROJECT_ID]: contributionSynced() }
    await flushPromises()

    expect({
      ferniteChecked: (wrapper.find(`[data-testid="intermediate-stock-proposal-select-${FERNITE_ALLOY.bomItemId}"]`).element as HTMLInputElement).checked,
      ferniteQuantity: fieldValue(wrapper, `intermediate-stock-proposal-quantity-${FERNITE_ALLOY.bomItemId}`),
      hydrogenQuantity: fieldValue(wrapper, `intermediate-stock-proposal-quantity-${HYDROGEN_FUEL_BLOCK.bomItemId}`),
    }).toEqual({ ferniteChecked: false, ferniteQuantity: '20', hydrogenQuantity: '60' })
  })
})

describe('intermediate stock proposal unknown state', () => {
  it.each<GroupProjectStatus>(['draft', 'published', 'in_progress', 'selling'])('shows the cause of an unknown stock on a %s project without opening a toggle', async status => {
    serveProposals(proposal([], 'assets_unknown', 'corporation_assets_not_synced'))

    const { wrapper } = await mountProposal(status)

    expect(textOf(wrapper, 'intermediate-stock-proposal-unknown')).toContain(en.groupIndustry.intermediateStockProposal.unknown.corporation_assets_not_synced)
    expect(wrapper.find('[data-testid="intermediate-stock-proposal-validate"]').exists()).toBe(false)
  })

  it('replaces the unknown cause by the lines once the assets are synced on an in-progress project', async () => {
    serveProposals(proposal([], 'assets_unknown', 'personal_assets_not_synced'), proposal([HYDROGEN_FUEL_BLOCK]))
    const { wrapper, syncStore } = await mountProposal('in_progress')
    const unknownBefore = wrapper.find('[data-testid="intermediate-stock-proposal-unknown"]').exists()

    syncStore.syncStatus['character-assets'] = syncProgress('character-assets', 'completed')
    await flushPromises()
    await wrapper.find('[data-testid="intermediate-stock-proposal-toggle"]').trigger('click')

    expect({ unknownBefore, unknownAfter: wrapper.find('[data-testid="intermediate-stock-proposal-unknown"]').exists(), rows: rowExists(wrapper, HYDROGEN_FUEL_BLOCK) }).toEqual({
      unknownBefore: true,
      unknownAfter: false,
      rows: true,
    })
  })

  it('renders nothing for a project that is not solo, whatever its status', async () => {
    serveProposals(proposal([], 'not_solo'))

    const { wrapper } = await mountProposal('in_progress')

    expect(wrapper.find(ROOT).exists()).toBe(false)
  })

  it('keeps a ready proposal folded behind the toggle on an in-progress project', async () => {
    serveProposals(proposal([HYDROGEN_FUEL_BLOCK]))

    const { wrapper } = await mountProposal('in_progress')

    expect({ toggle: wrapper.find('[data-testid="intermediate-stock-proposal-toggle"]').exists(), rows: rowExists(wrapper, HYDROGEN_FUEL_BLOCK) }).toEqual({ toggle: true, rows: false })
  })
})
