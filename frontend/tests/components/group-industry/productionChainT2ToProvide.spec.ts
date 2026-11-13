import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import { apiRequest } from '@/services/api'
import type { T2BlueprintToProvideLine } from '@/stores/group-industry/types'
import { PRODUCTION_CHAIN } from '../../productionChainNodes'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-wing'
const BLOCK = '[data-testid="chain-t2-to-provide"]'
const LONG_TYPE_NAME = 'Capital Ship Assembly Array Blueprint Copy for the Ishtar Heavy Assault Cruiser Fleet Refit Programme'
const BLANK = /[\u00a0\u202f]/g

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

const ISHTAR: T2BlueprintToProvideLine = { productTypeId: 12_005, typeName: 'Ishtar', runs: 15 }
const HAMMERHEAD_II: T2BlueprintToProvideLine = { productTypeId: 2_185, typeName: 'Hammerhead II', runs: 40 }
const LONG_NAMED: T2BlueprintToProvideLine = { productTypeId: 990_806, typeName: LONG_TYPE_NAME, runs: 7 }

async function mountChain(excludesInvention: boolean, lines: T2BlueprintToProvideLine[]): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation(async endpoint => {
    if (endpoint === `/industry/projects/${PROJECT_ID}/t2-blueprints-to-provide`) return { id: PROJECT_ID, excludesInvention, lines }
    if (endpoint === `/industry/projects/${PROJECT_ID}/reactions`) return { id: PROJECT_ID, steps: [] }
    return { id: PROJECT_ID, nodes: PRODUCTION_CHAIN }
  })
  setActivePinia(createPinia())
  const wrapper = mount(ProductionChain, {
    props: { projectId: PROJECT_ID },
    global: { plugins: [i18n] },
  })
  await flushPromises()
  return wrapper
}

function cellText(wrapper: VueWrapper, column: 'name' | 'runs', line: T2BlueprintToProvideLine): string {
  const cell = wrapper.find(`[data-testid="chain-t2-to-provide-${column}-${line.productTypeId}"]`)
  return cell.exists() ? cell.text().replace(BLANK, ' ') : 'absent'
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('ProductionChain T2 blueprint copies to provide (issue 806)', () => {
  it('lists one row per T2 product with its name and manufacturing runs when the invention is excluded', async () => {
    const wrapper = await mountChain(true, [HAMMERHEAD_II, ISHTAR, LONG_NAMED])

    expect(wrapper.find(BLOCK).exists()).toBe(true)
    expect(wrapper.find(BLOCK).text()).toContain(i18n.global.t('groupIndustry.chain.t2ToProvide.title'))
    expect([HAMMERHEAD_II, ISHTAR, LONG_NAMED].map(line => [cellText(wrapper, 'name', line), cellText(wrapper, 'runs', line)])).toEqual([
      ['Hammerhead II', '40'],
      ['Ishtar', '15'],
      [LONG_TYPE_NAME, '7'],
    ])
    expect(wrapper.find(`[data-testid="chain-t2-to-provide-name-${LONG_NAMED.productTypeId}"]`).attributes('title')).toBe(LONG_TYPE_NAME)
    expect(wrapper.findAll('[data-testid^="chain-t2-to-provide-row-"]')).toHaveLength(3)
  })

  it('has no block when the invention is not excluded', async () => {
    const wrapper = await mountChain(false, [])

    expect(wrapper.find(BLOCK).exists()).toBe(false)
  })

  it('says there is no T2 product in the plan when the invention is excluded and no line is returned', async () => {
    const wrapper = await mountChain(true, [])

    const empty = wrapper.find('[data-testid="chain-t2-to-provide-empty"]')
    expect(empty.exists() ? empty.text() : 'absent').toBe(i18n.global.t('groupIndustry.chain.t2ToProvide.empty'))
    expect(wrapper.findAll('[data-testid^="chain-t2-to-provide-row-"]')).toHaveLength(0)
  })
})
