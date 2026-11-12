import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StructureConfig from '@/components/industry/StructureConfig.vue'
import { apiRequest } from '@/services/api'
import type { RigOption } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

type Locale = 'en' | 'fr'

const STRUCTURES_PATH = '/industry/structures'
const CORPORATION_STRUCTURES_PATH = '/industry/corporation-structures'

const ME_RESEARCH_ACCELERATOR_II: RigOption = {
  name: 'Standup M-Set ME Research Accelerator II',
  bonus: 0,
  timeBonus: 24,
  category: 'Laboratory',
  size: 'M',
  targetCategories: [],
}

const ME_RESEARCH_COST_OPTIMIZATION_II: RigOption = {
  name: 'Standup M-Set ME Research Cost Optimization II',
  bonus: 0,
  costBonus: 12,
  category: 'Laboratory',
  size: 'M',
  targetCategories: [],
}

const SHIP_MANUFACTURING_MATERIAL_EFFICIENCY_II: RigOption = {
  name: 'Standup M-Set Basic Small Ship Manufacturing Material Efficiency II',
  bonus: 2.4,
  category: 'M-Set Ships ME',
  size: 'M',
  targetCategories: ['basic_small_ship'],
}

const BOTH_BONUSES_RIG: RigOption = {
  name: 'Standup M-Set Both Bonuses Test Rig',
  bonus: 0,
  timeBonus: 20,
  costBonus: 10,
  category: 'Laboratory',
  size: 'M',
  targetCategories: [],
}

function bonusLabelsOfRig(wrapper: VueWrapper, rigName: string): string[] {
  const entry = wrapper.findAll('button').find(candidate => candidate.text().includes(rigName.replace('Standup M-Set ', '')))
  expect(entry, `rig ${rigName}`).toBeDefined()
  return entry!.findAll('span.text-table-cell > span').map(label => label.text())
}

async function openRigDropdown(locale: Locale, query: string): Promise<VueWrapper> {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === STRUCTURES_PATH) {
      return {
        structures: [],
        rigOptions: {
          manufacturing: [ME_RESEARCH_ACCELERATOR_II, ME_RESEARCH_COST_OPTIMIZATION_II, SHIP_MANUFACTURING_MATERIAL_EFFICIENCY_II, BOTH_BONUSES_RIG],
          reaction: [],
        },
      }
    }
    if (endpoint === CORPORATION_STRUCTURES_PATH) return { structures: [] }
    return {}
  })
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(StructureConfig, {
    global: { plugins: [i18n], stubs: { teleport: true, FavoriteSystemsConfig: true } },
  })
  await flushPromises()
  const messages = locale === 'en' ? en : fr
  await wrapper.findAll('button').find(candidate => candidate.text() === messages.common.actions.add)!.trigger('click')
  await flushPromises()
  await wrapper.get(`input[placeholder="${messages.industry.structures.searchRig}"]`).setValue(query)
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('StructureConfig rig bonus labels (issue 724)', () => {
  it('states the time bonus of an accelerator as time and not as TE', async () => {
    const wrapper = await openRigDropdown('en', 'Research Accelerator')

    expect(bonusLabelsOfRig(wrapper, ME_RESEARCH_ACCELERATOR_II.name)).toEqual(['Time: -24%', '(M)'])
  })

  it('states the cost bonus of a cost optimization rig, which carries no time bonus', async () => {
    const wrapper = await openRigDropdown('en', 'Research Cost')

    expect(bonusLabelsOfRig(wrapper, ME_RESEARCH_COST_OPTIMIZATION_II.name)).toEqual(['Cost: -12%', '(M)'])
  })

  it('states the time and the cost bonus of a rig that carries both', async () => {
    const wrapper = await openRigDropdown('en', 'Both Bonuses')

    expect(bonusLabelsOfRig(wrapper, BOTH_BONUSES_RIG.name)).toEqual(['Time: -20%', 'Cost: -10%', '(M)'])
  })

  it('keeps the material bonus of a manufacturing rig', async () => {
    const wrapper = await openRigDropdown('en', 'Basic Small Ship')

    expect(bonusLabelsOfRig(wrapper, SHIP_MANUFACTURING_MATERIAL_EFFICIENCY_II.name)).toEqual(['ME: -2.4%', '(M)'])
  })

  it.each([
    ['en', 'Research Accelerator', ME_RESEARCH_ACCELERATOR_II, ['Time: -24%', '(M)']],
    ['fr', 'Research Accelerator', ME_RESEARCH_ACCELERATOR_II, ['Temps : -24%', '(M)']],
    ['fr', 'Research Cost', ME_RESEARCH_COST_OPTIMIZATION_II, ['Coût : -12%', '(M)']],
  ] as const)('labels the bonuses in %s', async (locale, query, rig, labels) => {
    const wrapper = await openRigDropdown(locale, query)

    expect(bonusLabelsOfRig(wrapper, rig.name)).toEqual(labels)
  })
})
