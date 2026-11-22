import { defineComponent, h } from 'vue'
import { enableAutoUnmount, flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import StationView from '@/views/GroupIndustry/StationView.vue'
import type { PlacedAction, ProjectOverview } from '@/stores/group-industry/types'
import { TEST_LOCALES, messageOf, type TestLocale } from '../../localeTexts'
import { stationQuantity, typeQuantities, STATION_A_ID, STATION_B_ID, TRITANIUM, ZYDRINE, projectOf } from '../../components/group-industry/projectFlowFixtures'
import { AZBEL_PLACE, placedAction } from '../../todoPlaceFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => ({ params: { locationId: String(1_040_000_000_002) }, query: {} })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const MainLayoutStub = defineComponent({
  setup: (_, { slots }) => () => h('div', slots.default?.()),
})

const AZBEL = { locationId: STATION_A_ID, stationName: 'Azbel' }
const RAITARU = { locationId: STATION_B_ID, stationName: 'Raitaru' }
const IN_ONE_HOUR = new Date(Date.now() + 3_600_000).toISOString()

enableAutoUnmount(afterEach)

interface Backend {
  projects?: unknown[]
  overviews?: Partial<ProjectOverview>[]
  actions?: PlacedAction[]
  quantities?: Record<string, unknown>
  bom?: Record<string, unknown>
  failing?: string[]
}

function projectRead(id: string, status = 'in_progress') {
  return { ...projectOf(), id, name: `Project ${id}`, status, ownerCharacterName: 'Mira Thessaly' }
}

function overviewRead(projectId: string, myRole: ProjectOverview['myRole'], ownerName = 'Mira Thessaly'): Partial<ProjectOverview> {
  return { projectId, myRole, ownerName }
}

function stubBackend(backend: Backend): void {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (path: string) => {
    if (backend.failing?.includes(path)) throw new Error(`read failed: ${path}`)
    if (path === '/industry/projects') return (backend.projects ?? []) as never
    if (path === '/industry/projects/overview') return (backend.overviews ?? []) as never
    if (path === '/industry/place-actions') return (backend.actions ?? []) as never
    const quantities = /^\/industry\/projects\/([^/]+)\/station-quantities$/.exec(path)
    if (quantities !== null) return (backend.quantities?.[quantities[1]] ?? []) as never
    const bom = /^\/industry\/projects\/([^/]+)\/bom$/.exec(path)
    if (bom !== null) return (backend.bom?.[bom[1]] ?? []) as never
    if (/^\/industry\/projects\/[^/]+\/sales$/.test(path)) return [] as never
    throw new Error(`unexpected read: ${path}`)
  })
}

async function mountStation(backend: Backend, locale: TestLocale = 'en'): Promise<VueWrapper> {
  stubBackend(backend)
  setActivePinia(createPinia())
  const wrapper = mount(StationView, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: { MainLayout: MainLayoutStub, IndustryModuleTabs: true, RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return wrapper
}

function squashed(text: string): string {
  return text.replace(/[\u00a0\u202f]/g, ' ').replace(/\s+/g, ' ').trim()
}

function textOf(wrapper: VueWrapper, selector: string): string {
  const element = wrapper.find(selector)
  return element.exists() ? squashed(element.text()) : ''
}

function twoProjectsAtAzbel(): Backend {
  return {
    projects: [projectRead('p1'), projectRead('p2'), projectRead('p3')],
    overviews: [overviewRead('p1', 'owner'), overviewRead('p2', 'member', 'Kaelen Voss'), overviewRead('p3', 'owner')],
    actions: [
      placedAction({ id: 'a1', code: 'launch_job', place: AZBEL_PLACE, projectId: 'p1', projectName: 'Project p1', typeId: ZYDRINE.typeId, typeName: ZYDRINE.typeName, quantity: 4, here: true, access: 'granted' }),
    ],
    quantities: {
      p1: [typeQuantities(TRITANIUM, [stationQuantity({ ...AZBEL, onSite: 5, inTransit: 20, out: 3 })])],
      p2: [typeQuantities(ZYDRINE, [stationQuantity({ ...AZBEL, toBring: 8 })])],
      p3: [typeQuantities(TRITANIUM, [stationQuantity({ ...RAITARU, onSite: 6 })])],
    },
    bom: {
      p1: [{ typeId: ZYDRINE.typeId, isJob: true, activityType: 'manufacturing', runs: 4, fulfilledQuantity: 0, purchasedQuantity: 0, jobMatches: [{ status: 'active', endDate: IN_ONE_HOUR, runs: 4, facilityId: STATION_A_ID }] }],
    },
  }
}

describe.each(TEST_LOCALES)('station view (%s)', locale => {
  it('names the station, its system and tells the user is there', async () => {
    const wrapper = await mountStation(twoProjectsAtAzbel(), locale)

    expect({
      title: textOf(wrapper, '[data-testid="station-title"]'),
      system: textOf(wrapper, '[data-testid="station-system"]'),
      here: textOf(wrapper, '[data-testid="station-here"]'),
    }).toEqual({ title: 'Azbel', system: 'Perimeter', here: messageOf(locale, 'groupIndustry.flow.here') })
  })

  it('summarises lines in use, arrivals, carry away and access', async () => {
    const wrapper = await mountStation(twoProjectsAtAzbel(), locale)

    expect({
      occupied: textOf(wrapper, '[data-testid="station-tile-occupied"] [data-testid="station-tile-value"]'),
      expected: textOf(wrapper, '[data-testid="station-tile-expected"] [data-testid="station-tile-value"]'),
      carryAway: textOf(wrapper, '[data-testid="station-tile-carry-away"] [data-testid="station-tile-value"]'),
      access: textOf(wrapper, '[data-testid="station-tile-access"] [data-testid="station-tile-value"]'),
    }).toEqual({ occupied: '1', expected: '1', carryAway: '1', access: messageOf(locale, 'groupIndustry.station.accessValue').replace('{granted}', '1').replace('{unknown}', '0') })
  })

  it('labels the tiles in the language of the user', async () => {
    const wrapper = await mountStation(twoProjectsAtAzbel(), locale)

    expect(wrapper.findAll('[data-testid="station-tile-label"]').map(label => squashed(label.text()))).toEqual([
      messageOf(locale, 'groupIndustry.station.tiles.occupied'),
      messageOf(locale, 'groupIndustry.station.tiles.expected'),
      messageOf(locale, 'groupIndustry.station.tiles.carryAway'),
      messageOf(locale, 'groupIndustry.station.tiles.access'),
    ])
  })

  it('shows one row per project of the user with its role and its cards', async () => {
    const wrapper = await mountStation(twoProjectsAtAzbel(), locale)

    const rows = wrapper.findAll('[data-testid="station-project-row"]')
    expect(rows.map(row => ({
      name: squashed(row.find('[data-testid="station-project-name"]').text()),
      role: squashed(row.find('[data-testid="station-project-role"]').text()),
      onSite: row.findAll('[data-testid="flow-cell-onSite"] [data-testid="flow-card"]').length,
      toBring: row.findAll('[data-testid="flow-cell-toBring"] [data-testid="flow-card"]').length,
    }))).toEqual([
      { name: 'Project p1', role: messageOf(locale, 'groupIndustry.station.youOwn'), onSite: 1, toBring: 1 },
      { name: 'Project p2', role: messageOf(locale, 'groupIndustry.station.ownedBy').replace('{name}', 'Kaelen Voss'), onSite: 0, toBring: 1 },
    ])
  })

  it('lists the actions to do here', async () => {
    const wrapper = await mountStation(twoProjectsAtAzbel(), locale)

    expect({
      title: textOf(wrapper, '[data-testid="station-todo-title"]'),
      actions: wrapper.findAll('[data-testid="station-todo"] [data-testid="todo-action"]').length,
    }).toEqual({ title: messageOf(locale, 'groupIndustry.station.todoHere'), actions: 1 })
  })

  it('links each project row to its project', async () => {
    const wrapper = await mountStation(twoProjectsAtAzbel(), locale)

    const targets = wrapper.findAllComponents(RouterLinkStub).filter(link => link.attributes('data-testid') === 'station-project-link').map(link => link.props('to'))
    expect(targets).toEqual([
      { name: 'industry-project', params: { id: 'p1' } },
      { name: 'industry-project', params: { id: 'p2' } },
    ])
  })

  it('says so when no project of the user does anything here', async () => {
    const wrapper = await mountStation({ projects: [projectRead('p1')], overviews: [overviewRead('p1', 'owner')], quantities: { p1: [] } }, locale)

    expect({
      empty: textOf(wrapper, '[data-testid="station-empty"]'),
      rows: wrapper.findAll('[data-testid="station-project-row"]').length,
      todo: wrapper.find('[data-testid="station-todo"]').exists(),
    }).toEqual({ empty: messageOf(locale, 'groupIndustry.station.empty'), rows: 0, todo: false })
  })

  it('shows an error when the projects cannot be read, without showing an empty station', async () => {
    const wrapper = await mountStation({ failing: ['/industry/projects'] }, locale)

    expect({
      error: textOf(wrapper, '[data-testid="station-error"]'),
      empty: wrapper.find('[data-testid="station-empty"]').exists(),
    }).toEqual({ error: expect.stringContaining(messageOf(locale, 'groupIndustry.station.loadError')), empty: false })
  })

  it('tells which project could not be read and still shows the others', async () => {
    const backend = twoProjectsAtAzbel()
    backend.failing = ['/industry/projects/p2/station-quantities']
    const wrapper = await mountStation(backend, locale)

    expect({
      unreadable: textOf(wrapper, '[data-testid="station-unreadable"]'),
      rows: wrapper.findAll('[data-testid="station-project-row"]').length,
    }).toEqual({ unreadable: expect.stringContaining('Project p2'), rows: 1 })
  })

  it('tells which project has quantities that are unavailable and why', async () => {
    const backend = twoProjectsAtAzbel()
    backend.quantities = { p1: [typeQuantities(TRITANIUM, null, 'plan_unavailable')], p2: [] }
    const wrapper = await mountStation(backend, locale)

    expect(textOf(wrapper, '[data-testid="station-unavailable"]')).toContain('Project p1')
  })
})
