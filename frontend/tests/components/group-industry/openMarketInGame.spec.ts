import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BomTable from '@/components/group-industry/BomTable.vue'
import GroupCompanion from '@/components/group-industry/GroupCompanion.vue'
import type { CompanionTasks } from '@/components/group-industry/companionTasks'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupProject } from '@/stores/group-industry/types'
import { bomLine, companionTasksOf } from './companionFixtures'

vi.mock('@/services/api', () => ({ apiRequest: vi.fn() }))

const EmptyView = { render: () => null }
const OPEN_MARKET_BUTTON = 'button[aria-label="Open market in-game"]'
const OPEN_MARKET_BUTTON_FR = 'button[aria-label="Ouvrir le marche en jeu"]'
const MATERIAL_ROW = 'tbody tr'
const MINE_LINE = '[data-testid="companion-mine-line"]'
const FREE_LINE = '[data-testid="companion-free-line"]'
const TRITANIUM_TYPE_ID = 34
const MORPHITE_TYPE_ID = 11399
const FEROX_TYPE_ID = 16_227

function pathOf(call: unknown[]): string {
  return call[0] as string
}

function bodyOf(call: unknown[]): unknown {
  return JSON.parse((call[1] as { body: string }).body)
}

const SABRE_PROJECT = {
  id: 'project-sabre',
  name: 'Sabre doctrine',
  status: 'in_progress',
  shortLinkCode: 'sabre01',
  containerNames: [],
  ownerCharacterName: 'Aria Solberg',
  ownerCorporationId: 98000001,
  membersCount: 3,
  contributionsCount: 0,
  items: [{ typeId: 22456, typeName: 'Sabre', meLevel: 10, teLevel: 20, runs: 10 }],
  totalBomValue: 0,
  fulfillmentPercent: 0,
  approvedContributionValue: 0,
  brokerFeePercent: 3.6,
  salesTaxPercent: 3.6,
  lineRentalRatesOverride: null,
  blacklistGroupIds: [],
  blacklistTypeIds: [],
  createdAt: '2026-10-20T18:30:00+00:00',
  myRole: 'owner',
  myStatus: 'accepted',
  private: false,
  notes: null,
  acceptsOutsideFavoriteSystem: false,
  excludesInvention: false,
  minimumOutsideSaving: 5,
  sellStructureId: null,
  sellStructureName: null,
} as GroupProject

const tritanium: BomItem = bomLine({ id: 'bom-tritanium', typeId: TRITANIUM_TYPE_ID, typeName: 'Tritanium', freeQuantity: 1_000 })
const morphite: BomItem = bomLine({ id: 'bom-morphite', typeId: MORPHITE_TYPE_ID, typeName: 'Morphite', freeQuantity: 37 })
const ferox: BomItem = bomLine({
  id: 'bom-ferox',
  typeId: FEROX_TYPE_ID,
  typeName: 'Ferox',
  isJob: true,
  activityType: 'manufacturing',
  runs: 4,
  freeQuantity: 4,
  blueprintName: 'Ferox Blueprint',
})

function mountBomTable(bomItems: BomItem[], locale: 'en' | 'fr' = 'en'): VueWrapper {
  setActivePinia(createPinia())
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = SABRE_PROJECT
  projectStore.bomItems = bomItems
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/appraisal', name: 'appraisal', component: EmptyView }] })
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(BomTable, { global: { plugins: [i18n, router] } })
}

function mountCompanion(tasks: CompanionTasks): VueWrapper {
  return mount(GroupCompanion, {
    props: { tasks, projectName: 'Ferox batch', size: 'column', errorMessage: null },
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue({ success: true, error: null })
})

describe('Materials to Source open market in game', () => {
  it('offers one button per material line, each opening the market of its own type', async () => {
    const wrapper = mountBomTable([tritanium, morphite])
    const rows = wrapper.findAll(MATERIAL_ROW)

    for (const row of rows) await row.get(OPEN_MARKET_BUTTON).trigger('click')
    await flushPromises()

    expect({
      rows: rows.length,
      calls: vi.mocked(apiRequest).mock.calls.map(call => [pathOf(call), bodyOf(call)]),
    }).toEqual({
      rows: 2,
      calls: [
        ['/me/open-window/market', { typeId: TRITANIUM_TYPE_ID }],
        ['/me/open-window/market', { typeId: MORPHITE_TYPE_ID }],
      ],
    })
  })

  it('reports success on the clicked line only', async () => {
    const wrapper = mountBomTable([tritanium, morphite])
    const [first, second] = wrapper.findAll(MATERIAL_ROW)

    await first.get(OPEN_MARKET_BUTTON).trigger('click')
    await flushPromises()

    expect({
      first: first.find('button[title="Window opened in-game"]').exists(),
      second: second.find('button[title="Window opened in-game"]').exists(),
    }).toEqual({ first: true, second: false })
  })

  it('reports a failure when the game refuses the window, for instance without the UI scope', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ success: false, error: 'missing scope' })
    const wrapper = mountBomTable([tritanium])

    await wrapper.get(OPEN_MARKET_BUTTON).trigger('click')
    await flushPromises()

    expect(wrapper.find('button[title="Error opening window"]').exists()).toBe(true)
  })

  it('titles the button in French', () => {
    const wrapper = mountBomTable([tritanium], 'fr')

    expect(wrapper.find(OPEN_MARKET_BUTTON_FR).exists()).toBe(true)
  })

  it('keeps the table free of any request until a button is clicked', () => {
    mountBomTable([tritanium, morphite])

    expect(apiRequest).not.toHaveBeenCalled()
  })
})

describe('Companion open market in game', () => {
  it('offers the button on a material line to deliver and opens the market of that type', async () => {
    const wrapper = mountCompanion(companionTasksOf({ mine: [{ item: tritanium, quantity: 500 }] }))

    await wrapper.get(`${MINE_LINE} ${OPEN_MARKET_BUTTON}`).trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest).mock.calls.map(call => [pathOf(call), bodyOf(call)])).toEqual([
      ['/me/open-window/market', { typeId: TRITANIUM_TYPE_ID }],
    ])
  })

  it('offers no button on a job line to deliver', () => {
    const wrapper = mountCompanion(companionTasksOf({ mine: [{ item: ferox, quantity: 4 }] }))

    expect(wrapper.get(MINE_LINE).find(OPEN_MARKET_BUTTON).exists()).toBe(false)
  })

  it('keeps the deliver and release buttons on a material line that carries the market button', async () => {
    const wrapper = mountCompanion(companionTasksOf({ mine: [{ item: tritanium, quantity: 500 }] }))
    const line = wrapper.get(MINE_LINE)

    await line.get('[data-testid="companion-deliver"]').trigger('click')
    await line.get('[data-testid="companion-release"]').trigger('click')

    expect({
      deliver: wrapper.emitted('deliver'),
      release: wrapper.emitted('release'),
      requests: vi.mocked(apiRequest).mock.calls.length,
    }).toEqual({ deliver: [['bom-tritanium']], release: [['bom-tritanium']], requests: 0 })
  })

  it('offers no button on the lines still to assign', () => {
    const wrapper = mountCompanion(companionTasksOf({ free: [tritanium] }))

    expect(wrapper.get(FREE_LINE).find(OPEN_MARKET_BUTTON).exists()).toBe(false)
  })
})
