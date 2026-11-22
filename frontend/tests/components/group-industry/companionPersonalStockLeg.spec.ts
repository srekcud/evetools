import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import GroupCompanion from '@/components/group-industry/GroupCompanion.vue'
import type { CompanionTasks } from '@/components/group-industry/companionTasks'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useGroupCompanionStore } from '@/stores/group-industry/companion'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import {
  bomLine,
  companionLegOf,
  companionProject,
  companionTasksOf,
  COMPANION_PROJECT_ID,
  MAIN_EVE_CHARACTER_ID,
  projectTransportLegs,
  transportLeg,
  transportLegLine,
} from './companionFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_PATH = `/industry/projects/${COMPANION_PROJECT_ID}`
const TRANSFERS_PATH = `${PROJECT_PATH}/station-transfers`
const PERSONAL_STOCK_HINT = '[data-testid="companion-leg-personal-stock-hint"]'
const LEG = '[data-testid="companion-leg"]'
const LEG_DECLARE = '[data-testid="companion-leg-declare"]'
const PERSONAL_STOCK_LEG_KEY = 'jita>perimeter-personal'
const PROJECT_LEG_KEY = 'jita>perimeter-project'

function mountCompanion(tasks: CompanionTasks, locale: 'en' | 'fr' = 'en'): VueWrapper {
  return mount(GroupCompanion, {
    props: { tasks, projectName: 'Ishtar batch', size: 'column', errorMessage: null },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

function legsTasks(): CompanionTasks {
  return companionTasksOf({
    legs: [
      companionLegOf({ key: PERSONAL_STOCK_LEG_KEY, lines: [transportLegLine({ source: 'personal_stock' })] }),
      companionLegOf({ key: PROJECT_LEG_KEY, lines: [transportLegLine({ source: 'project' })] }),
    ],
    readyLegCount: 2,
  })
}

describe('companion Transport tab: leg departing from my personal stock (#1365)', () => {
  it.each([['en', 'Use my stock'], ['fr', 'Utiliser mon stock']] as const)('points a leg leaving my personal stock to the contribution action in %s, and only that leg', (locale, actionLabel) => {
    const wrapper = mountCompanion(legsTasks(), locale)

    const hintsByLeg = wrapper.findAll(LEG).map(leg => leg.findAll(PERSONAL_STOCK_HINT).map(hint => hint.text().includes(actionLabel)))

    expect(hintsByLeg).toEqual([[true], []])
  })

  it('declares a personal stock leg as a transfer alone, without asking for any contribution', async () => {
    const wrapper = mountCompanion(legsTasks())

    await wrapper.findAll(LEG)[0].find(LEG_DECLARE).trigger('click')

    expect(Object.keys(wrapper.emitted())).toEqual(expect.arrayContaining(['declare-leg']))
    expect({ declared: wrapper.emitted('declare-leg'), supplied: wrapper.emitted('supply'), delivered: wrapper.emitted('deliver') }).toEqual({
      declared: [[PERSONAL_STOCK_LEG_KEY]],
      supplied: undefined,
      delivered: undefined,
    })
  })

  it('posts the transfer of a personal stock leg and nothing else', async () => {
    setActivePinia(createPinia())
    useAuthStore().user = { characters: [{ eveCharacterId: MAIN_EVE_CHARACTER_ID, name: 'Alpha' }] } as never
    vi.mocked(apiRequest).mockReset()
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
      if (endpoint === PROJECT_PATH) return companionProject()
      if (endpoint === `${PROJECT_PATH}/bom`) return [bomLine({ id: 'bom-morphite', typeId: 11_399, typeName: 'Morphite' })]
      if (endpoint === `${PROJECT_PATH}/chain`) return { id: COMPANION_PROJECT_ID, nodes: [] }
      if (endpoint === `${PROJECT_PATH}/cockpit`) return { priceAlert: null, undercutOrders: [] }
      if (endpoint === `${PROJECT_PATH}/transport-legs`) {
        return projectTransportLegs({ legs: [transportLeg({ key: PERSONAL_STOCK_LEG_KEY, lines: [transportLegLine({ source: 'personal_stock', quantity: 40 })] })] })
      }
      if (options?.method === 'POST') return {}
      return []
    })
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)

    await store.declareLeg(PERSONAL_STOCK_LEG_KEY)

    const writes = vi.mocked(apiRequest).mock.calls.filter(([, options]) => options?.method !== undefined && options.method !== 'GET')
    expect(writes.map(([endpoint, options]) => [endpoint, options?.method, JSON.parse(String(options?.body)).lines])).toEqual([
      [TRANSFERS_PATH, 'POST', [{ typeId: 11_399, quantity: 40 }]],
    ])
  })
})
