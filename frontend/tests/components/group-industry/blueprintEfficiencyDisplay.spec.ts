import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProductionChainJobTree from '@/components/group-industry/ProductionChainJobTree.vue'
import { showsBlueprintEfficiency } from '@/components/group-industry/blueprintEfficiency'
import type { ChainActivity } from '@/stores/group-industry/types'
import { chainNode } from '../../productionChainNodes'

function i18n() {
  return createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
}

function chainTreeShowsMeTe(activity: ChainActivity): boolean {
  setActivePinia(createPinia())
  const node = chainNode({ id: `node-${activity}`, stage: 'component', activity, runs: 1, meLevel: 7, teLevel: 13 })
  const wrapper = mount(ProductionChainJobTree, { props: { nodes: [node], selectedId: null }, global: { plugins: [i18n()] } })
  return wrapper.text().includes('ME 7 · TE 13')
}

describe('blueprint efficiency display', () => {
  it.each([
    ['manufacturing', true],
    ['invention', true],
    ['reaction', false],
    ['copy', false],
  ] as const)('shows ME/TE for a %s node of the chain tree: %s', (activity, shown) => {
    expect(chainTreeShowsMeTe(activity)).toBe(shown)
  })

  it('keeps ME/TE for a line without activity', () => {
    expect(showsBlueprintEfficiency(null)).toBe(true)
  })
})
