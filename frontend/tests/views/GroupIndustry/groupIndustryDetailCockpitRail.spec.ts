import { enableAutoUnmount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import type { ProjectPhaseKey } from '@/stores/group-industry/types'
import { TEST_LOCALES } from '../../localeTexts'
import {
  attributeOf,
  bomLine,
  cockpitOf,
  COCKPIT_RAIL,
  mountCockpitDetail,
  nextAction,
  NEXT_ACTION_CARD,
  nyxProject,
  PHASE_KEYS,
  phase,
  phaseSelector,
  route,
  tabSelector,
  textOf,
} from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const FRENCH_PHASE_LABELS: Record<ProjectPhaseKey, string> = {
  prepare: 'Préparer',
  supply: 'Approvisionner',
  produce: 'Produire',
  sell: 'Vendre',
  close: 'Clôturer',
}

const LONG_PROJECT_NAME = 'Operation Blackwater Capital Dreadnought Assembly Line Alpha Bravo Charlie Delta Echo Foxtrot Golf Hotel India'
const LONG_TYPE_NAME = 'Capital Construction Parts Advanced Component Assembly Array Mark Two Blueprint Original Copy Variant Prototype'

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('project cockpit rail', () => {
  it('shows the five phases in order with their counter, the blocked ones with their reason in French', async () => {
    const wrapper = await mountCockpitDetail({
      project: nyxProject('selling'),
      cockpit: cockpitOf('selling', nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 }), [
        phase('prepare', 'done', 2, 2),
        phase('supply', 'blocked', 2, 5, { code: 'materials_missing', count: 3 }),
        phase('produce', 'blocked', 4, 6, { code: 'jobs_unfinished', count: 2 }),
        phase('sell', 'current', 60, 100),
        phase('close', 'upcoming', 0, 1),
      ]),
    }, 'fr')

    const cells = wrapper.findAll('[data-testid^="cockpit-phase-"]')
    const text = (key: ProjectPhaseKey) => textOf(wrapper, phaseSelector(key))
    expect({
      order: cells.map(cell => cell.attributes('data-testid')),
      states: PHASE_KEYS.map(key => attributeOf(wrapper, phaseSelector(key), 'data-state')),
      labels: PHASE_KEYS.map(key => text(key).includes(FRENCH_PHASE_LABELS[key])),
      counters: [['prepare', '2 / 2'], ['supply', '2 / 5'], ['produce', '4 / 6'], ['sell', '60 / 100'], ['close', '0 / 1']]
        .map(([key, counter]) => text(key as ProjectPhaseKey).includes(counter)),
      materialsReason: text('supply').includes('3 matériaux à couvrir'),
      jobsReason: text('produce').replace('4 / 6', '').includes('2') && !text('produce').includes('jobs_unfinished'),
    }).toEqual({
      order: PHASE_KEYS.map(key => `cockpit-phase-${key}`),
      states: ['done', 'blocked', 'blocked', 'current', 'upcoming'],
      labels: [true, true, true, true, true],
      counters: [true, true, true, true, true],
      materialsReason: true,
      jobsReason: true,
    })
  })

  it('shows neither the rail nor the next action card when the cockpit cannot be loaded, the tabs stay usable', async () => {
    const wrapper = await mountCockpitDetail({ project: nyxProject('in_progress'), cockpitFailure: 'Cockpit unavailable' })

    expect({
      rail: wrapper.find(COCKPIT_RAIL).exists(),
      card: wrapper.find(NEXT_ACTION_CARD).exists(),
      title: wrapper.find('h1').text(),
      bomTab: wrapper.find(tabSelector('bom')).exists(),
      membersTab: wrapper.find(tabSelector('members')).exists(),
    }).toEqual({ rail: false, card: false, title: 'Nyx batch', bomTab: true, membersTab: true })
  })

  it('renders the rail and the card with the full project and type names in French and in English', async () => {
    const rendered: Record<string, { phases: number; card: boolean; projectName: boolean; typeName: boolean }> = {}

    for (const locale of TEST_LOCALES) {
      const wrapper = await mountCockpitDetail({
        project: nyxProject('in_progress', 'owner', { name: LONG_PROJECT_NAME }),
        bom: [bomLine({ id: 'line-long', typeId: 40_001, typeName: LONG_TYPE_NAME, remainingQuantity: 1850 })],
        cockpit: cockpitOf('in_progress', nextAction({
          code: 'source_material',
          blocked: true,
          tab: 'materials',
          bomLineId: 'line-long',
          typeId: 40_001,
          typeName: LONG_TYPE_NAME,
          quantity: 1850,
        })),
      }, locale)
      rendered[locale] = {
        phases: wrapper.findAll('[data-testid^="cockpit-phase-"]').length,
        card: wrapper.find(NEXT_ACTION_CARD).exists(),
        projectName: wrapper.text().includes(LONG_PROJECT_NAME),
        typeName: textOf(wrapper, NEXT_ACTION_CARD).includes(LONG_TYPE_NAME),
      }
      wrapper.unmount()
    }

    expect(rendered).toEqual({
      fr: { phases: 5, card: true, projectName: true, typeName: true },
      en: { phases: 5, card: true, projectName: true, typeName: true },
    })
  })
})
