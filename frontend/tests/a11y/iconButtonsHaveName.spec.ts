import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, h, reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import CharacterCard from '@/components/characters/CharacterCard.vue'
import EscalationRow from '@/components/escalation/EscalationRow.vue'
import EscalationsTab from '@/components/ledger/EscalationsTab.vue'
import PveTab from '@/components/ledger/PveTab.vue'
import NewProjectModal from '@/components/group-industry/NewProjectModal.vue'
import ProjectDetail from '@/components/industry/ProjectDetail.vue'
import ProjectTable from '@/components/industry/ProjectTable.vue'
import StructureConfig from '@/components/industry/StructureConfig.vue'
import GroupIndustryDetail from '@/views/GroupIndustry/GroupIndustryDetail.vue'
import { useEscalationStore, type Escalation } from '@/stores/escalation'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { GroupProject } from '@/stores/group-industry/types'
import { useProjectsStore } from '@/stores/industry/projects'
import type { IndustryProject, StructureConfig as StructureConfigRecord } from '@/stores/industry/types'
import { apiRequest, authFetch } from '@/services/api'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: { id: 'group-project' }, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
  authFetch: vi.fn(),
}))

type Locale = 'en' | 'fr'

const RAW_TRANSLATION_KEY = /^[a-zA-Z0-9]+(\.[a-zA-Z0-9]+)+$/
const FAR_FUTURE = '2099-01-01T00:00:00+00:00'
const VEXOR_TYPE_ID = 626
const WIDOW_TYPE_ID = 22436
const OWNED_CHARACTER_ID = 2112000001
const SECOND_PAGE_ESCALATION_COUNT = 21

const stubbedMainLayout = { MainLayout: { template: '<div><slot /></div>' } }

function i18nFor(locale: Locale) {
  return createI18n({ legacy: false, locale, messages: { en, fr } })
}

function allButtons(wrapper: VueWrapper): Element[] {
  return Array.from((wrapper.element as Element).querySelectorAll<Element>('button, [role="button"]'))
}

function buttonMatching(wrapper: VueWrapper, matches: (button: DOMWrapper<Element>) => boolean) {
  const button = wrapper.findAll('button').find(matches)
  if (!button) throw new Error('No button matches')
  return button
}

function iconOnlyButtons(wrapper: VueWrapper): Element[] {
  return allButtons(wrapper)
    .filter(button => button.querySelector('svg') !== null && (button.textContent ?? '').trim() === '')
}

function accessibleName(button: Element): string {
  const label = button.getAttribute('aria-label')?.trim()
  if (label) return label
  const labelledBy = button.getAttribute('aria-labelledby')
  if (labelledBy) {
    const root = button.getRootNode() as ParentNode
    return labelledBy
      .split(/\s+/)
      .map(id => root.querySelector(`[id="${id}"]`)?.textContent?.trim() ?? '')
      .join(' ')
      .trim()
  }
  return (button.textContent ?? '').trim()
}

function buttonsWithoutAccessibleName(wrapper: VueWrapper): string[] {
  return allButtons(wrapper)
    .map(button => ({ button, name: accessibleName(button) }))
    .filter(({ name }) => name === '' || RAW_TRANSLATION_KEY.test(name))
    .map(({ button }) => button.outerHTML.slice(0, 600))
}

function jsonResponse(body: unknown): Response {
  return new Response(JSON.stringify(body), { status: 200 })
}

const STRUCTURE: StructureConfigRecord = {
  id: 'structure',
  name: 'Raitaru in Jita',
  locationId: null,
  solarSystemId: null,
  securityType: 'highsec',
  structureType: 'raitaru',
  rigs: ['Standard Manufacturing Efficiency I'],
  isDefault: false,
  isCorporationStructure: false,
  manufacturingMaterialBonus: 1,
  reactionMaterialBonus: 0,
  manufacturingTimeBonus: 15,
  reactionTimeBonus: 0,
  facilityTaxRate: 1,
  createdAt: '2026-10-01T10:00:00+00:00',
}

const PVE_INCOME = {
  period: { from: '2026-09-28', to: '2026-10-28', days: 30 },
  lastSyncAt: '2026-10-28T08:00:00+00:00',
  bounties: { total: 0, count: 0, entries: [] },
  lootSales: { total: 500_000, count: 1, entries: [{ id: 'sale', type: 'loot', description: 'Overseer loot', amount: 500_000, date: '2026-10-27T00:00:00+00:00' }] },
  expenses: { total: 100_000, byType: { fuel: 100_000 } },
  profit: 400_000,
}
const PVE_EXPENSES = [{ id: 'expense', type: 'fuel', description: 'Nitrogen isotopes', amount: 100_000, date: '2026-10-27T00:00:00+00:00' }]
const PVE_SETTINGS = {
  ammoTypes: [{ typeId: 2629, typeName: 'Antimatter Charge S' }],
  lootTypes: [{ typeId: 30747, typeName: 'Overseer Personal Effects' }],
  declinedContractsCount: 1,
  declinedTransactionsCount: 0,
}

function respondToPveRequests() {
  vi.mocked(authFetch).mockImplementation(async (input, init) => {
    const url = String(input)
    if (url.startsWith('/api/pve/income')) return jsonResponse(PVE_INCOME)
    if (url.startsWith('/api/pve/expenses')) return jsonResponse(PVE_EXPENSES)
    if (url === '/api/pve/settings') return jsonResponse(PVE_SETTINGS)
    if (url === '/api/pve/sync' && init?.method === 'POST') return jsonResponse({ status: 'partial', message: 'wallet unavailable' })
    return jsonResponse({})
  })
}

function respondToStructureRequests() {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === '/industry/structures') return { structures: [STRUCTURE], rigOptions: { manufacturing: [], reaction: [] } }
    if (endpoint === '/industry/corporation-structures') return { structures: [] }
    return {}
  })
}

function escalation(overrides: Partial<Escalation>): Escalation {
  return {
    id: 'escalation',
    characterId: OWNED_CHARACTER_ID,
    characterName: 'Ilyra Castell',
    type: 'Serpentis',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    securityStatus: -0.5,
    price: 100,
    visibility: 'perso',
    bmStatus: 'nouveau',
    saleStatus: 'envente',
    notes: null,
    corporationId: 98000001,
    expiresAt: FAR_FUTURE,
    createdAt: '2026-10-28T08:00:00+00:00',
    updatedAt: '2026-10-28T08:00:00+00:00',
    isOwner: true,
    ...overrides,
  }
}

function industryProject(overrides: Partial<IndustryProject>): IndustryProject {
  return {
    id: 'project',
    productTypeName: 'Vexor',
    productTypeId: VEXOR_TYPE_ID,
    displayName: null,
    runs: 3,
    meLevel: 8,
    teLevel: 16,
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
    createdAt: '2026-10-24T09:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    ...overrides,
  }
}

const GROUP_PROJECT: GroupProject = {
  id: 'group-project',
  name: 'Widow run',
  status: 'draft',
  shortLinkCode: 'abc123',
  containerName: null,
  ownerCharacterName: 'Ilyra Castell',
  ownerCorporationId: 98000001,
  membersCount: 1,
  items: [],
  totalBomValue: 0,
  fulfillmentPercent: 0,
  approvedContributionValue: 0,
  brokerFeePercent: 3,
  salesTaxPercent: 3,
  lineRentalRatesOverride: null,
  blacklistGroupIds: [],
  blacklistTypeIds: [],
  createdAt: '2026-10-24T09:00:00+00:00',
  myRole: 'owner',
  myStatus: 'accepted',
}

const shoppingTabWithClearStockModalOpen = defineComponent({
  name: 'ShoppingTab',
  data: () => ({ showClearStockModal: true }),
  methods: {
    activate: () => undefined,
    loadSuggestions: () => undefined,
    loadPersistedStock: () => undefined,
  },
  render: () => h('div'),
})

interface MountedComponent {
  wrapper: VueWrapper
  expectedIconOnlyButtons: number
}

async function mountCharacterCard(locale: Locale): Promise<MountedComponent> {
  const wrapper = mount(CharacterCard, {
    props: {
      character: {
        id: 'character',
        eveCharacterId: OWNED_CHARACTER_ID,
        name: 'Ilyra Castell',
        corporationId: 98000001,
        corporationName: 'Castell Industries',
        allianceId: null,
        allianceName: null,
        isMain: false,
        hasValidToken: false,
        hasMissingScopes: true,
        lastSyncAt: null,
      },
      wallet: null,
      skillQueue: undefined,
      isLoading: false,
      variant: 'other',
    },
    global: { plugins: [i18nFor(locale)] },
  })
  return { wrapper, expectedIconOnlyButtons: 3 }
}

async function mountEscalationRow(locale: Locale): Promise<MountedComponent> {
  const wrapper = mount(EscalationRow, {
    props: {
      escalation: escalation({}),
      timerInfo: { text: '99h', percent: 80, zone: 'safe' },
      activeVisPopoverId: null,
      timerBarColor: 'bg-emerald-500',
      timerTextColor: 'text-emerald-400',
      priceColorClass: 'text-slate-200',
      priceSubColorClass: 'text-slate-500',
      secStatusColorClass: 'text-red-400',
      visibilityBadgeClasses: 'bg-slate-500/20',
      visibilityLabel: 'Perso',
      visibilityTitle: 'Personal',
      showShareButtons: true,
    },
    global: { plugins: [i18nFor(locale)] },
  })
  return { wrapper, expectedIconOnlyButtons: 4 }
}

async function mountEscalationsTab(locale: Locale): Promise<MountedComponent> {
  const escalationStore = useEscalationStore()
  vi.spyOn(escalationStore, 'fetchEscalations').mockResolvedValue(undefined as never)
  vi.spyOn(escalationStore, 'fetchCorpEscalations').mockResolvedValue(undefined as never)
  escalationStore.escalations = Array.from({ length: SECOND_PAGE_ESCALATION_COUNT }, (_, index) => escalation({ id: `escalation-${index}` }))
  const wrapper = mount(EscalationsTab, {
    global: { plugins: [i18nFor(locale)], stubs: { teleport: true } },
  })
  await flushPromises()
  return { wrapper, expectedIconOnlyButtons: 1 + 3 * 20 + 4 }
}

async function mountPveTab(locale: Locale): Promise<MountedComponent> {
  respondToPveRequests()
  const wrapper = mount(PveTab, {
    props: { selectedDays: 30 },
    global: {
      plugins: [i18nFor(locale)],
      stubs: { teleport: true, PveDashboard: true, IncomeChart: true, ProfitTrendChart: true, ExpenseBreakdownChart: true },
    },
  })
  await flushPromises()
  const messages = locale === 'en' ? en.pve : fr.pve
  await buttonMatching(wrapper, button => button.attributes('title') === messages.syncTooltip).trigger('click')
  await flushPromises()
  await buttonMatching(wrapper, button => button.attributes('title') === messages.configAmmo).trigger('click')
  await buttonMatching(wrapper, button => button.attributes('aria-label') === messages.configLootTypes).trigger('click')
  await flushPromises()
  return { wrapper, expectedIconOnlyButtons: 11 }
}

async function mountProjectTable(locale: Locale): Promise<MountedComponent> {
  useProjectsStore().projects = [industryProject({ id: 'vexor' }), industryProject({ id: 'widow', productTypeId: WIDOW_TYPE_ID, productTypeName: 'Widow' })]
  const wrapper = mount(ProjectTable, { global: { plugins: [i18nFor(locale)], stubs: { teleport: true } } })
  return { wrapper, expectedIconOnlyButtons: 4 * 2 }
}

async function mountNewProjectModal(locale: Locale): Promise<MountedComponent> {
  const wrapper = mount(NewProjectModal, {
    props: { show: true },
    global: { plugins: [i18nFor(locale)], stubs: { teleport: true } },
  })
  const labels = locale === 'en' ? en.groupIndustry.modals.newProject : fr.groupIndustry.modals.newProject
  const clickButton = async (label: string) => buttonMatching(wrapper, button => button.text() === label).trigger('click')
  await clickButton(labels.bulkPaste)
  await wrapper.get('textarea').setValue('Ferox 2 4 x100')
  await clickButton(labels.parseAndAdd)
  Object.assign(wrapper.vm, { blacklistItems: [{ typeId: 11993, typeName: 'Cormorant' }] })
  await flushPromises()
  return { wrapper, expectedIconOnlyButtons: 3 }
}

async function mountStructureConfig(locale: Locale): Promise<MountedComponent> {
  respondToStructureRequests()
  const wrapper = mount(StructureConfig, {
    global: { plugins: [i18nFor(locale)], stubs: { teleport: true, FavoriteSystemsConfig: true } },
  })
  await flushPromises()
  const editLabel = locale === 'en' ? en.common.actions.edit : fr.common.actions.edit
  await buttonMatching(wrapper, button => button.attributes('aria-label') === editLabel).trigger('click')
  await flushPromises()
  return { wrapper, expectedIconOnlyButtons: 3 }
}

async function mountProjectDetail(locale: Locale): Promise<MountedComponent> {
  const projectsStore = useProjectsStore()
  projectsStore.currentProject = { ...industryProject({ id: 'project-detail' }), steps: [], tree: null }
  vi.spyOn(projectsStore, 'fetchProject').mockResolvedValue(undefined as never)
  const wrapper = mount(ProjectDetail, {
    props: { projectId: 'project-detail' },
    global: {
      plugins: [i18nFor(locale)],
      stubs: { teleport: true, StepsTab: true, BpcKitTab: true, CostEstimationTab: true, ShoppingTab: shoppingTabWithClearStockModalOpen },
    },
  })
  await flushPromises()
  return { wrapper, expectedIconOnlyButtons: 3 }
}

async function mountGroupIndustryDetail(locale: Locale): Promise<MountedComponent> {
  const groupProjectStore = useGroupProjectStore()
  vi.spyOn(groupProjectStore, 'fetchProjectDetail').mockResolvedValue(undefined as never)
  vi.spyOn(groupProjectStore, 'fetchBom').mockResolvedValue(undefined as never)
  groupProjectStore.currentProject = GROUP_PROJECT
  const wrapper = mount(GroupIndustryDetail, {
    global: {
      plugins: [i18nFor(locale)],
      stubs: {
        ...stubbedMainLayout,
        teleport: true,
        BomTable: true,
        JobsTable: true,
        ContainerBanner: true,
        ContributeModal: true,
        ContributionTable: true,
        SalesTable: true,
        FinancialSummary: true,
        DistributionTable: true,
        MemberTable: true,
        PendingRequestsTable: true,
        ShareLinkCard: true,
      },
    },
  })
  await flushPromises()
  const editLabel = locale === 'en' ? en.groupIndustry.detail.edit : fr.groupIndustry.detail.edit
  await buttonMatching(wrapper, button => button.attributes('aria-label') === editLabel).trigger('click')
  await flushPromises()
  return { wrapper, expectedIconOnlyButtons: 4 }
}

const COMPONENTS: [string, (locale: Locale) => Promise<MountedComponent>][] = [
  ['CharacterCard', mountCharacterCard],
  ['EscalationRow', mountEscalationRow],
  ['EscalationsTab', mountEscalationsTab],
  ['PveTab', mountPveTab],
  ['ProjectTable', mountProjectTable],
  ['NewProjectModal', mountNewProjectModal],
  ['StructureConfig', mountStructureConfig],
  ['ProjectDetail', mountProjectDetail],
  ['GroupIndustryDetail', mountGroupIndustryDetail],
]

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(authFetch).mockReset()
  vi.spyOn(console, 'error').mockImplementation(() => undefined)
})

describe('buttons that only contain an icon', () => {
  it('are detected when they have no accessible name', () => {
    const wrapper = mount({ template: '<div><button><svg /></button><button aria-label="Close"><svg /></button><button><svg /> Save</button></div>' })

    expect(buttonsWithoutAccessibleName(wrapper)).toHaveLength(1)
  })

  it.each(COMPONENTS.flatMap(([name, mountComponent]) => (['en', 'fr'] as const).map(locale => [name, locale, mountComponent] as const)))(
    '%s has an accessible name on every button in %s',
    async (_name, locale, mountComponent) => {
      const { wrapper, expectedIconOnlyButtons } = await mountComponent(locale)

      expect(iconOnlyButtons(wrapper)).toHaveLength(expectedIconOnlyButtons)
      expect(buttonsWithoutAccessibleName(wrapper)).toEqual([])
    },
  )
})
