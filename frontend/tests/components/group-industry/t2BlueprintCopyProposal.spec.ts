import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import T2BlueprintCopyProposal from '@/components/group-industry/T2BlueprintCopyProposal.vue'
import { ApiError, apiRequest } from '@/services/api'
import type {
  GroupProjectStatus,
  T2BlueprintCopyProposal as T2BlueprintCopyProposalResource,
  T2BlueprintCopyProposalLine,
  T2BlueprintCopyProposalState,
  T2BlueprintCopyUnknownReason,
} from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const PROJECT_ID = 'project-ishtar-wing'
const PROPOSAL_PATH = `/industry/projects/${PROJECT_ID}/t2-blueprint-copy-proposal`
const DECLARE_PATH = `/industry/projects/${PROJECT_ID}/t2-blueprint-copies`
const BOM_PATH = `/industry/projects/${PROJECT_ID}/bom`
const ROOT = '[data-testid="t2-blueprint-copy-proposal"]'
const PREFIX = 't2-blueprint-copy-proposal'
const BLANK = /[\u00a0\u202f]/g

const ISHTAR: T2BlueprintCopyProposalLine = {
  productTypeId: 12_005,
  typeName: 'Ishtar',
  manufacturingRuns: 15,
  personalRuns: 10,
  containerRuns: 3,
  availableRuns: 13,
  proposedRuns: 13,
  declaredRuns: 0,
}
const HAMMERHEAD_II: T2BlueprintCopyProposalLine = {
  productTypeId: 2_185,
  typeName: 'Hammerhead II',
  manufacturingRuns: 40,
  personalRuns: 25,
  containerRuns: null,
  availableRuns: 25,
  proposedRuns: 25,
  declaredRuns: 0,
}
const WARRIOR_II: T2BlueprintCopyProposalLine = {
  productTypeId: 2_486,
  typeName: 'Warrior II',
  manufacturingRuns: 30,
  personalRuns: 12,
  containerRuns: 6,
  availableRuns: 18,
  proposedRuns: 18,
  declaredRuns: 7,
}

const UNKNOWN_REASONS: T2BlueprintCopyUnknownReason[] = [
  'blueprints_not_synced',
  'container_not_found',
  'no_director',
  'corporation_assets_not_synced',
  'no_visible_division',
  'owner_without_corporation',
]

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

function text(key: string): string {
  return i18n.global.t(key).replace(BLANK, ' ')
}

function proposal(
  lines: T2BlueprintCopyProposalLine[],
  state: T2BlueprintCopyProposalState = 'ready',
  unknownReason: T2BlueprintCopyUnknownReason | null = null,
): T2BlueprintCopyProposalResource {
  return { id: PROJECT_ID, state, unknownReason, lines }
}

function serveProposals(...responses: T2BlueprintCopyProposalResource[]): void {
  let served = 0
  vi.mocked(apiRequest).mockImplementation(async (path: string, options?: RequestInit) => {
    if (path === PROPOSAL_PATH) {
      const response = responses[Math.min(served, responses.length - 1)]
      served += 1
      return response
    }
    if (options?.method === 'POST') return {}
    return []
  })
}

function refuseDeclaration(message: string): void {
  const served = vi.mocked(apiRequest).getMockImplementation()
  if (served === undefined) throw new Error('serveProposals must come first')
  vi.mocked(apiRequest).mockImplementation(async (path: string, options?: RequestInit) => {
    if (path === DECLARE_PATH && options?.method === 'POST') throw new ApiError(message, 400)
    return served(path, options)
  })
}

async function mountProposal(projectStatus: GroupProjectStatus = 'draft'): Promise<VueWrapper> {
  setActivePinia(createPinia())
  const wrapper = mount(T2BlueprintCopyProposal, { props: { projectId: PROJECT_ID, projectStatus }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function declarations(): Array<Record<string, unknown>> {
  return vi.mocked(apiRequest).mock.calls
    .filter(([path, options]) => path === DECLARE_PATH && options?.method === 'POST')
    .map(([, options]) => JSON.parse(String(options?.body)))
}

function callSequence(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([path, options]) => `${options?.method ?? 'GET'} ${path}`)
}

function rowExists(wrapper: VueWrapper, line: T2BlueprintCopyProposalLine): boolean {
  return wrapper.find(`[data-testid="${PREFIX}-row-${line.productTypeId}"]`).exists()
}

function textOf(wrapper: VueWrapper, testId: string): string {
  const found = wrapper.find(`[data-testid="${testId}"]`)
  return found.exists() ? found.text().replace(BLANK, ' ') : 'absent'
}

function cellOf(wrapper: VueWrapper, line: T2BlueprintCopyProposalLine, column: string): string {
  return textOf(wrapper, `${PREFIX}-cell-${line.productTypeId}-${column}`)
}

function runsOf(wrapper: VueWrapper, line: T2BlueprintCopyProposalLine): string {
  const found = wrapper.find(`[data-testid="${PREFIX}-runs-${line.productTypeId}"]`)
  return found.exists() ? (found.element as HTMLInputElement).value : 'absent'
}

function isChecked(wrapper: VueWrapper, line: T2BlueprintCopyProposalLine): boolean | string {
  const found = wrapper.find(`[data-testid="${PREFIX}-select-${line.productTypeId}"]`)
  return found.exists() ? (found.element as HTMLInputElement).checked : 'absent'
}

function isDeclareDisabled(wrapper: VueWrapper): boolean | string {
  const found = wrapper.find(`[data-testid="${PREFIX}-declare"]`)
  return found.exists() ? found.attributes('disabled') !== undefined : 'absent'
}

async function setField(wrapper: VueWrapper, testId: string, value: boolean | string): Promise<void> {
  const found = wrapper.find(`[data-testid="${testId}"]`)
  if (found.exists()) await found.setValue(value)
}

async function click(wrapper: VueWrapper, testId: string): Promise<void> {
  const found = wrapper.find(`[data-testid="${testId}"]`)
  if (found.exists()) await found.trigger('click')
  await flushPromises()
}

async function declare(wrapper: VueWrapper): Promise<void> {
  await click(wrapper, `${PREFIX}-declare`)
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('T2 blueprint copy proposal panel', () => {
  it('reads the proposal of the project and lists each line with its runs by source and an editable number of runs set to the proposed runs', async () => {
    serveProposals(proposal([ISHTAR, HAMMERHEAD_II]))

    const wrapper = await mountProposal()

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(PROPOSAL_PATH)
    expect(wrapper.find(ROOT).exists()).toBe(true)
    expect(textOf(wrapper, `${PREFIX}-row-${ISHTAR.productTypeId}`)).toContain('Ishtar')
    expect([
      cellOf(wrapper, ISHTAR, 'manufacturing'),
      cellOf(wrapper, ISHTAR, 'personal'),
      cellOf(wrapper, ISHTAR, 'container'),
      cellOf(wrapper, ISHTAR, 'available'),
    ]).toEqual(['15', '10', '3', '13'])
    expect([
      cellOf(wrapper, HAMMERHEAD_II, 'manufacturing'),
      cellOf(wrapper, HAMMERHEAD_II, 'personal'),
      cellOf(wrapper, HAMMERHEAD_II, 'container'),
      cellOf(wrapper, HAMMERHEAD_II, 'available'),
    ]).toEqual(['40', '25', '—', '25'])
    expect([runsOf(wrapper, ISHTAR), runsOf(wrapper, HAMMERHEAD_II)]).toEqual(['13', '25'])
  })

  it('checks a line that is already declared and shows its declared runs instead of the proposed runs', async () => {
    serveProposals(proposal([HAMMERHEAD_II, WARRIOR_II]))

    const wrapper = await mountProposal()

    expect(isChecked(wrapper, WARRIOR_II)).toBe(true)
    expect(runsOf(wrapper, WARRIOR_II)).toBe('7')
    expect(runsOf(wrapper, HAMMERHEAD_II)).toBe('25')
  })

  it('opens the panel on a draft project and folds it behind the Declare my T2 blueprint copies button on any other status', async () => {
    serveProposals(proposal([ISHTAR]))
    const draft = await mountProposal('draft')
    const draftShowsRows = rowExists(draft, ISHTAR)
    const draftHasToggle = draft.find(`[data-testid="${PREFIX}-toggle"]`).exists()

    serveProposals(proposal([ISHTAR]))
    const inProgress = await mountProposal('in_progress')
    const foldedShowsRows = rowExists(inProgress, ISHTAR)
    const toggleLabel = textOf(inProgress, `${PREFIX}-toggle`)
    await click(inProgress, `${PREFIX}-toggle`)
    const openedShowsRows = rowExists(inProgress, ISHTAR)

    expect({ draftShowsRows, draftHasToggle, foldedShowsRows, toggleLabel, openedShowsRows }).toEqual({
      draftShowsRows: true,
      draftHasToggle: false,
      foldedShowsRows: false,
      toggleLabel: text('groupIndustry.t2BlueprintCopyProposal.toggle'),
      openedShowsRows: true,
    })
  })

  it('explains each unknown reason, including blueprints_not_synced, instead of listing copies', async () => {
    const explained: Record<string, string> = {}
    const listed: Record<string, boolean> = {}
    for (const reason of UNKNOWN_REASONS) {
      serveProposals(proposal([], 'assets_unknown', reason))
      const wrapper = await mountProposal()
      explained[reason] = textOf(wrapper, `${PREFIX}-unknown`)
      listed[reason] = wrapper.findAll(`[data-testid^="${PREFIX}-row-"]`).length > 0 || wrapper.find(`[data-testid="${PREFIX}-declare"]`).exists()
    }

    for (const reason of UNKNOWN_REASONS) {
      expect(explained[reason]).toContain(text(`groupIndustry.t2BlueprintCopyProposal.unknown.${reason}`))
      expect(listed[reason]).toBe(false)
    }
    expect(new Set(UNKNOWN_REASONS.map(reason => text(`groupIndustry.t2BlueprintCopyProposal.unknown.${reason}`))).size).toBe(UNKNOWN_REASONS.length)
  })

  it('renders nothing for a project that is not solo', async () => {
    serveProposals(proposal([], 'not_solo'))

    const wrapper = await mountProposal()

    expect(wrapper.find(ROOT).exists()).toBe(false)
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('says no T2 blueprint copy is owned for the plan, with no declare button, when the proposal is ready without a line', async () => {
    serveProposals(proposal([]))

    const wrapper = await mountProposal()

    expect(textOf(wrapper, `${PREFIX}-empty`)).toBe(text('groupIndustry.t2BlueprintCopyProposal.empty'))
    expect(wrapper.find(`[data-testid="${PREFIX}-declare"]`).exists()).toBe(false)
  })

  it('keeps the runs between one and the proposed runs and refuses to declare outside that range', async () => {
    serveProposals(proposal([ISHTAR, HAMMERHEAD_II]))
    const wrapper = await mountProposal()
    await setField(wrapper, `${PREFIX}-select-${ISHTAR.productTypeId}`, true)
    const field = wrapper.find(`[data-testid="${PREFIX}-runs-${ISHTAR.productTypeId}"]`)
    const bounds = { min: field.attributes('min'), max: field.attributes('max') }

    await setField(wrapper, `${PREFIX}-runs-${ISHTAR.productTypeId}`, '14')
    const aboveProposed = isDeclareDisabled(wrapper)
    await declare(wrapper)
    await setField(wrapper, `${PREFIX}-runs-${ISHTAR.productTypeId}`, '0')
    const belowOne = isDeclareDisabled(wrapper)
    await declare(wrapper)
    await setField(wrapper, `${PREFIX}-runs-${ISHTAR.productTypeId}`, '13')
    const atProposed = isDeclareDisabled(wrapper)

    expect({ bounds, aboveProposed, belowOne, atProposed, posted: declarations() }).toEqual({
      bounds: { min: '1', max: '13' },
      aboveProposed: true,
      belowOne: true,
      atProposed: false,
      posted: [],
    })
  })

  it('posts one declaration with only the checked lines and their edited runs, then reads the proposal and the BOM again and emits declared', async () => {
    serveProposals(proposal([ISHTAR, HAMMERHEAD_II, WARRIOR_II]), proposal([ISHTAR, HAMMERHEAD_II, { ...WARRIOR_II, declaredRuns: 7 }]))
    const wrapper = await mountProposal()
    await setField(wrapper, `${PREFIX}-select-${ISHTAR.productTypeId}`, true)
    await setField(wrapper, `${PREFIX}-select-${WARRIOR_II.productTypeId}`, true)
    await setField(wrapper, `${PREFIX}-select-${HAMMERHEAD_II.productTypeId}`, false)
    await setField(wrapper, `${PREFIX}-runs-${WARRIOR_II.productTypeId}`, '9')

    await declare(wrapper)

    expect(declarations()).toEqual([
      { copies: [{ productTypeId: ISHTAR.productTypeId, runs: 13 }, { productTypeId: WARRIOR_II.productTypeId, runs: 9 }] },
    ])
    const sequence = callSequence()
    const post = sequence.indexOf(`POST ${DECLARE_PATH}`)
    expect(sequence.filter(call => call === `POST ${DECLARE_PATH}`)).toHaveLength(1)
    expect(sequence.slice(post + 1)).toEqual(expect.arrayContaining([`GET ${PROPOSAL_PATH}`, `GET ${BOM_PATH}`]))
    expect(wrapper.emitted('declared')).toHaveLength(1)
  })

  it('posts an empty declaration to withdraw every declared copy when all lines are unchecked', async () => {
    serveProposals(proposal([HAMMERHEAD_II, WARRIOR_II]), proposal([HAMMERHEAD_II, { ...WARRIOR_II, declaredRuns: 0 }]))
    const wrapper = await mountProposal()
    await setField(wrapper, `${PREFIX}-select-${WARRIOR_II.productTypeId}`, false)

    await declare(wrapper)

    expect(declarations()).toEqual([{ copies: [] }])
    expect(wrapper.emitted('declared')).toHaveLength(1)
  })

  it('shows the message of the server and emits nothing when the declaration is refused with a 400', async () => {
    serveProposals(proposal([ISHTAR, WARRIOR_II]))
    refuseDeclaration('Declared runs above the T2 blueprint copies available for Ishtar')
    const wrapper = await mountProposal()
    await setField(wrapper, `${PREFIX}-select-${ISHTAR.productTypeId}`, true)

    await declare(wrapper)

    expect(declarations()).toHaveLength(1)
    expect(textOf(wrapper, `${PREFIX}-error`)).toContain('Declared runs above the T2 blueprint copies available for Ishtar')
    expect(wrapper.emitted('declared')).toBeUndefined()
  })

  it('renders nothing and does not fail when the proposal is absent', async () => {
    vi.mocked(apiRequest).mockImplementation(async () => undefined as never)

    const draft = await mountProposal('draft')
    const published = await mountProposal('in_progress')

    expect(draft.find(ROOT).exists()).toBe(false)
    expect(published.find(ROOT).exists()).toBe(false)
    expect(draft.findAll('button')).toHaveLength(0)
    expect(published.findAll('button')).toHaveLength(0)
  })

  it('has the labels in English and French', () => {
    const keys = [
      'title', 'toggle', 'loading', 'loadError', 'empty', 'unknownTitle', 'declare', 'declaring', 'declarationFailed',
      'columns.type', 'columns.manufacturing', 'columns.personal', 'columns.container', 'columns.available', 'columns.runs',
      ...UNKNOWN_REASONS.map(reason => `unknown.${reason}`),
    ]
    const missing = (['en', 'fr'] as const).flatMap(locale => keys.filter(key => i18n.global.te(`groupIndustry.t2BlueprintCopyProposal.${key}`, locale) === false))

    expect(missing).toEqual([])
  })
})
