import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import IntermediateStockProposal from '@/components/group-industry/IntermediateStockProposal.vue'
import { ApiError, apiRequest } from '@/services/api'
import type {
  GroupProjectStatus,
  IntermediateStockProposal as IntermediateStockProposalResource,
  IntermediateStockProposalLine,
  IntermediateStockProposalState,
  IntermediateStockStationQuantity,
  IntermediateStockUnknownReason,
} from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const PROJECT_ID = 'project-ferrite-run'
const PROPOSAL_PATH = `/industry/projects/${PROJECT_ID}/intermediate-stock-proposal`
const CONTRIBUTIONS_PATH = `/industry/projects/${PROJECT_ID}/contributions`
const BOM_PATH = `/industry/projects/${PROJECT_ID}/bom`
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
const CERAMIC_POWDER: IntermediateStockProposalLine = {
  bomItemId: 'line-ceramic-powder',
  typeId: 16_660,
  typeName: 'Ceramic Powder',
  demandQuantity: 200,
  remainingQuantity: 200,
  personalQuantity: 25,
  containerQuantity: null,
  stockQuantity: 25,
  proposedQuantity: 25,
  stockByStation: [],
  plannedStationName: null,
}

const ISHTAR_STATION_ON_SITE: IntermediateStockStationQuantity = {
  locationId: 1_035_466_617_946,
  locationName: 'Ishtar Citadel',
  systemName: 'Gamis',
  quantity: 12_500,
  onSite: true,
}
const JITA_STATION_OFF_SITE: IntermediateStockStationQuantity = {
  locationId: 60_003_760,
  locationName: 'Jita IV - Moon 4',
  systemName: 'Jita',
  quantity: 40,
  onSite: false,
}
const AMARR_STATION_UNKNOWN_SITE: IntermediateStockStationQuantity = {
  locationId: 60_008_494,
  locationName: 'Amarr VIII (Oris)',
  systemName: 'Amarr',
  quantity: 275,
  onSite: null,
}
const ROBOTICS_BY_STATION: IntermediateStockProposalLine = {
  bomItemId: 'line-robotics',
  typeId: 11_542,
  typeName: 'Robotics',
  demandQuantity: 300,
  remainingQuantity: 300,
  personalQuantity: 12_540,
  containerQuantity: null,
  stockQuantity: 12_540,
  proposedQuantity: 300,
  stockByStation: [ISHTAR_STATION_ON_SITE, JITA_STATION_OFF_SITE],
  plannedStationName: 'Ishtar',
}

const UNKNOWN_REASONS: IntermediateStockUnknownReason[] = [
  'personal_assets_not_synced',
  'container_not_found',
  'no_director',
  'corporation_assets_not_synced',
  'no_visible_division',
  'owner_without_corporation',
]

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

function text(key: string, params: Record<string, string> = {}): string {
  return i18n.global.t(key, params).replace(BLANK, ' ')
}

function proposal(
  lines: IntermediateStockProposalLine[],
  state: IntermediateStockProposalState = 'ready',
  unknownReason: IntermediateStockUnknownReason | null = null,
): IntermediateStockProposalResource {
  return { id: PROJECT_ID, state, unknownReason, lines }
}

function serveProposals(...responses: IntermediateStockProposalResource[]): void {
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

function refusePostOf(bomItemId: string, message: string): void {
  const served = vi.mocked(apiRequest).getMockImplementation()
  if (served === undefined) throw new Error('serveProposals must come first')
  vi.mocked(apiRequest).mockImplementation(async (path: string, options?: RequestInit) => {
    if (options?.method === 'POST' && JSON.parse(String(options.body)).bomItemId === bomItemId) {
      throw new ApiError(message, 400)
    }
    return served(path, options)
  })
}

async function mountProposal(projectStatus: GroupProjectStatus = 'draft'): Promise<VueWrapper> {
  setActivePinia(createPinia())
  const wrapper = mount(IntermediateStockProposal, { props: { projectId: PROJECT_ID, projectStatus }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function postedContributions(): Array<Record<string, unknown>> {
  return vi.mocked(apiRequest).mock.calls
    .filter(([path, options]) => path === CONTRIBUTIONS_PATH && options?.method === 'POST')
    .map(([, options]) => JSON.parse(String(options?.body)))
}

function callSequence(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([path, options]) => `${options?.method ?? 'GET'} ${path}`)
}

function rowText(wrapper: VueWrapper, line: IntermediateStockProposalLine): string {
  const found = wrapper.find(`[data-testid="intermediate-stock-proposal-row-${line.bomItemId}"]`)
  return found.exists() ? found.text().replace(BLANK, ' ') : 'absent'
}

function stationsOf(wrapper: VueWrapper, line: IntermediateStockProposalLine): string {
  return textOf(wrapper, `intermediate-stock-proposal-stations-${line.bomItemId}`)
}

function textOf(wrapper: VueWrapper, testId: string): string {
  const found = wrapper.find(`[data-testid="${testId}"]`)
  return found.exists() ? found.text().replace(BLANK, ' ') : 'absent'
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

async function validate(wrapper: VueWrapper): Promise<void> {
  await click(wrapper, 'intermediate-stock-proposal-validate')
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('intermediate stock proposal panel', () => {
  it('reads the proposal of the project and lists each line with an editable quantity set to the proposed quantity', async () => {
    serveProposals(proposal([HYDROGEN_FUEL_BLOCK, FERNITE_ALLOY]))

    const wrapper = await mountProposal()

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(PROPOSAL_PATH)
    expect(rowText(wrapper, HYDROGEN_FUEL_BLOCK)).toContain('Hydrogen Fuel Block')
    expect(rowText(wrapper, HYDROGEN_FUEL_BLOCK)).toContain('100')
    expect(rowText(wrapper, HYDROGEN_FUEL_BLOCK)).toContain('30')
    expect(rowText(wrapper, HYDROGEN_FUEL_BLOCK)).toContain('50')
    expect(rowText(wrapper, FERNITE_ALLOY)).toContain('Fernite Alloy')
    expect([HYDROGEN_FUEL_BLOCK, FERNITE_ALLOY].map(line => (wrapper.find(`[data-testid="intermediate-stock-proposal-quantity-${line.bomItemId}"]`).element as HTMLInputElement).value))
      .toEqual(['80', '45'])
    expect([HYDROGEN_FUEL_BLOCK, FERNITE_ALLOY].map(line => (wrapper.find(`[data-testid="intermediate-stock-proposal-select-${line.bomItemId}"]`).element as HTMLInputElement).checked))
      .toEqual([true, true])
  })

  it.each(UNKNOWN_REASONS)('explains the reason %s instead of listing the stock', async reason => {
    serveProposals(proposal([], 'assets_unknown', reason))

    const wrapper = await mountProposal()

    expect(textOf(wrapper, 'intermediate-stock-proposal-unknown')).toContain(text(`groupIndustry.intermediateStockProposal.unknown.${reason}`))
    expect(wrapper.findAll('[data-testid^="intermediate-stock-proposal-row-"]')).toHaveLength(0)
    expect(wrapper.find('[data-testid="intermediate-stock-proposal-validate"]').exists()).toBe(false)
  })

  it('renders nothing for a project that is not solo', async () => {
    serveProposals(proposal([], 'not_solo'))

    const wrapper = await mountProposal()

    expect(wrapper.find(ROOT).exists()).toBe(false)
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('says nothing was found in the stock, with no validation button, when the proposal is ready without a line', async () => {
    serveProposals(proposal([]))

    const wrapper = await mountProposal()

    expect(textOf(wrapper, 'intermediate-stock-proposal-empty')).toBe(text('groupIndustry.intermediateStockProposal.empty'))
    expect(wrapper.find('[data-testid="intermediate-stock-proposal-validate"]').exists()).toBe(false)
  })

  it('opens the panel on a draft project and folds it behind the Use my stock button on any other status', async () => {
    serveProposals(proposal([HYDROGEN_FUEL_BLOCK]))
    const draft = await mountProposal('draft')
    const draftShowsRows = draft.find(`[data-testid="intermediate-stock-proposal-row-${HYDROGEN_FUEL_BLOCK.bomItemId}"]`).exists()
    const draftHasToggle = draft.find('[data-testid="intermediate-stock-proposal-toggle"]').exists()

    serveProposals(proposal([HYDROGEN_FUEL_BLOCK]))
    const inProgress = await mountProposal('in_progress')
    const foldedShowsRows = inProgress.find(`[data-testid="intermediate-stock-proposal-row-${HYDROGEN_FUEL_BLOCK.bomItemId}"]`).exists()
    const toggleLabel = textOf(inProgress, 'intermediate-stock-proposal-toggle')
    await click(inProgress, 'intermediate-stock-proposal-toggle')
    const openedShowsRows = inProgress.find(`[data-testid="intermediate-stock-proposal-row-${HYDROGEN_FUEL_BLOCK.bomItemId}"]`).exists()

    expect({ draftShowsRows, draftHasToggle, foldedShowsRows, toggleLabel, openedShowsRows }).toEqual({
      draftShowsRows: true,
      draftHasToggle: false,
      foldedShowsRows: false,
      toggleLabel: text('groupIndustry.intermediateStockProposal.toggle'),
      openedShowsRows: true,
    })
  })

  it('posts one material contribution per checked line in the order of the list, with the edited quantity', async () => {
    serveProposals(proposal([HYDROGEN_FUEL_BLOCK, FERNITE_ALLOY, CERAMIC_POWDER]))
    const wrapper = await mountProposal()
    await setField(wrapper, `intermediate-stock-proposal-select-${FERNITE_ALLOY.bomItemId}`, false)
    await setField(wrapper, `intermediate-stock-proposal-quantity-${CERAMIC_POWDER.bomItemId}`, '20')

    await validate(wrapper)

    expect(postedContributions()).toEqual([
      { bomItemId: HYDROGEN_FUEL_BLOCK.bomItemId, type: 'material', quantity: 80 },
      { bomItemId: CERAMIC_POWDER.bomItemId, type: 'material', quantity: 20 },
    ])
  })

  it('reads the proposal again between two posts, skips a line that disappeared and lowers a quantity above the new maximum', async () => {
    const hydrogenAfterTheFirstPost = { ...HYDROGEN_FUEL_BLOCK }
    const fernitePartlyCovered = { ...FERNITE_ALLOY, proposedQuantity: 40, stockQuantity: 40 }
    serveProposals(
      proposal([HYDROGEN_FUEL_BLOCK, FERNITE_ALLOY, CERAMIC_POWDER]),
      proposal([hydrogenAfterTheFirstPost, fernitePartlyCovered, CERAMIC_POWDER]),
      proposal([hydrogenAfterTheFirstPost, fernitePartlyCovered]),
    )
    const wrapper = await mountProposal()

    await validate(wrapper)

    expect(postedContributions()).toEqual([
      { bomItemId: HYDROGEN_FUEL_BLOCK.bomItemId, type: 'material', quantity: 80 },
      { bomItemId: FERNITE_ALLOY.bomItemId, type: 'material', quantity: 40 },
    ])
    const sequence = callSequence()
    const firstPost = sequence.indexOf(`POST ${CONTRIBUTIONS_PATH}`)
    const secondPost = sequence.indexOf(`POST ${CONTRIBUTIONS_PATH}`, firstPost + 1)
    expect(sequence.slice(firstPost + 1, secondPost)).toContain(`GET ${PROPOSAL_PATH}`)
  })

  it('stops at a refused contribution, shows the message of the server and reads the proposal and the BOM again', async () => {
    serveProposals(proposal([HYDROGEN_FUEL_BLOCK, FERNITE_ALLOY, CERAMIC_POWDER]))
    refusePostOf(FERNITE_ALLOY.bomItemId, 'Intermediate stock above what the plan still needs')
    const wrapper = await mountProposal()

    await validate(wrapper)

    expect(postedContributions().map(posted => posted.bomItemId)).toEqual([HYDROGEN_FUEL_BLOCK.bomItemId, FERNITE_ALLOY.bomItemId])
    expect(textOf(wrapper, 'intermediate-stock-proposal-error')).toContain('Intermediate stock above what the plan still needs')
    const sequence = callSequence()
    const refusedPost = sequence.lastIndexOf(`POST ${CONTRIBUTIONS_PATH}`)
    expect(sequence.slice(refusedPost + 1)).toEqual(expect.arrayContaining([`GET ${PROPOSAL_PATH}`, `GET ${BOM_PATH}`]))
  })

  it('lists the stations of a line in a sub-row with name, system and formatted quantity while the Personal column and the quantity field stay unchanged', async () => {
    serveProposals(proposal([ROBOTICS_BY_STATION]))

    const wrapper = await mountProposal()

    const stations = stationsOf(wrapper, ROBOTICS_BY_STATION)
    expect(['Ishtar Citadel', 'Gamis', '12,500', 'Jita IV - Moon 4', 'Jita', '40'].filter(part => !stations.includes(part))).toEqual([])
    expect(stations.indexOf('Ishtar Citadel')).toBeLessThan(stations.indexOf('Jita IV - Moon 4'))
    expect(rowText(wrapper, ROBOTICS_BY_STATION)).toContain('12,540')
    expect((wrapper.find(`[data-testid="intermediate-stock-proposal-quantity-${ROBOTICS_BY_STATION.bomItemId}"]`).element as HTMLInputElement).value).toBe('300')
  })

  it('marks a station on site with the On site badge and no Off site badge', async () => {
    serveProposals(proposal([{ ...ROBOTICS_BY_STATION, stockByStation: [ISHTAR_STATION_ON_SITE] }]))

    const wrapper = await mountProposal()

    const stations = stationsOf(wrapper, ROBOTICS_BY_STATION)
    expect(stations).toContain('Ishtar Citadel')
    expect(stations).toContain(text('groupIndustry.intermediateStockProposal.stations.onSite'))
    expect(stations).not.toContain(text('groupIndustry.intermediateStockProposal.stations.offSite'))
  })

  it('marks a station off site with the Off site badge and no On site badge', async () => {
    serveProposals(proposal([{ ...ROBOTICS_BY_STATION, stockByStation: [JITA_STATION_OFF_SITE], plannedStationName: null }]))

    const wrapper = await mountProposal()

    const stations = stationsOf(wrapper, ROBOTICS_BY_STATION)
    expect(stations).toContain('Jita IV - Moon 4')
    expect(stations).toContain(text('groupIndustry.intermediateStockProposal.stations.offSite'))
    expect(stations).not.toContain(text('groupIndustry.intermediateStockProposal.stations.onSite'))
  })

  it('shows no badge for a station whose site is unknown', async () => {
    serveProposals(proposal([{ ...ROBOTICS_BY_STATION, stockByStation: [AMARR_STATION_UNKNOWN_SITE], plannedStationName: null }]))

    const wrapper = await mountProposal()

    const stations = stationsOf(wrapper, ROBOTICS_BY_STATION)
    expect(stations).toContain('Amarr VIII (Oris)')
    expect(stations).toContain('275')
    expect(stations).not.toContain(text('groupIndustry.intermediateStockProposal.stations.onSite'))
    expect(stations).not.toContain(text('groupIndustry.intermediateStockProposal.stations.offSite'))
  })

  it('says nothing is on site at the station of the plan when every entry is off site', async () => {
    serveProposals(proposal([{ ...ROBOTICS_BY_STATION, stockByStation: [JITA_STATION_OFF_SITE] }]))

    const wrapper = await mountProposal()

    expect(stationsOf(wrapper, ROBOTICS_BY_STATION)).toContain(text('groupIndustry.intermediateStockProposal.stations.noneOnSite', { station: 'Ishtar' }))
  })

  it('renders no station sub-row for a line without any station entry', async () => {
    serveProposals(proposal([{ ...ROBOTICS_BY_STATION, stockByStation: [] }]))

    const wrapper = await mountProposal()

    expect(rowText(wrapper, ROBOTICS_BY_STATION)).toContain('Robotics')
    expect(stationsOf(wrapper, ROBOTICS_BY_STATION)).toBe('absent')
  })

  it('has the labels in English and French', () => {
    const keys = [
      'title', 'toggle', 'loading', 'loadError', 'empty', 'unknownTitle', 'validate', 'validating', 'validationFailed',
      'columns.type', 'columns.remaining', 'columns.personal', 'columns.container', 'columns.stock', 'columns.quantity',
      'stations.onSite', 'stations.offSite', 'stations.noneOnSite',
      ...UNKNOWN_REASONS.map(reason => `unknown.${reason}`),
    ]
    const missing = (['en', 'fr'] as const).flatMap(locale => keys.filter(key => i18n.global.te(`groupIndustry.intermediateStockProposal.${key}`, locale) === false))

    expect(missing).toEqual([])
  })
})
