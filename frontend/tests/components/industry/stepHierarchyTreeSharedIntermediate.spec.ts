import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StepHierarchyTree from '@/components/industry/StepHierarchyTree.vue'
import type { ProductionTreeNode, TreeMaterial } from '@/stores/industry/types'

const HYDROGEN_FUEL_BLOCK = 4246
const FERNITE_ALLOY = 16656
const CERAMIC_POWDER = 16660
const FERNITE_CARBIDE = 16673
const SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET = 1_000_000

function rawMaterial(typeId: number, typeName: string, quantity: number): TreeMaterial {
  return { typeId, typeName, quantity, isBuildable: false }
}

function referenceTo(typeId: number, typeName: string, quantity: number): TreeMaterial {
  return { typeId, typeName, quantity, isBuildable: true }
}

function node(productTypeId: number, productTypeName: string, quantity: number, runs: number, depth: number, activityType: string, materials: TreeMaterial[]): ProductionTreeNode {
  return {
    blueprintTypeId: productTypeId + SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET,
    productTypeId,
    productTypeName,
    quantity,
    runs,
    depth,
    activityType,
    hasCopy: false,
    materials,
    structureBonus: 0,
    structureName: null,
    productCategory: null,
  }
}

function buildable(child: ProductionTreeNode): TreeMaterial {
  return { typeId: child.productTypeId, typeName: child.productTypeName, quantity: child.quantity, isBuildable: true, blueprint: child }
}

const HYDROGEN_FUEL_BLOCK_NODE = node(HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 15, 1, 1, 'manufacturing', [
  rawMaterial(44, 'Enriched Uranium', 4),
  rawMaterial(17889, 'Hydrogen Isotopes', 405),
])

const FERNITE_ALLOY_NODE = node(FERNITE_ALLOY, 'Fernite Alloy', 98, 1, 1, 'reaction', [
  referenceTo(HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 5),
  rawMaterial(16639, 'Scandium', 98),
  rawMaterial(16642, 'Vanadium', 97),
])

const CERAMIC_POWDER_NODE = node(CERAMIC_POWDER, 'Ceramic Powder', 96, 1, 1, 'reaction', [
  referenceTo(HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 4),
  rawMaterial(16635, 'Evaporite Deposits', 96),
  rawMaterial(16636, 'Silicates', 95),
])

const FERNITE_CARBIDE_TREE = node(FERNITE_CARBIDE, 'Fernite Carbide', 10000, 1, 0, 'reaction', [
  { ...buildable(HYDROGEN_FUEL_BLOCK_NODE), quantity: 6 },
  buildable(FERNITE_ALLOY_NODE),
  buildable(CERAMIC_POWDER_NODE),
])

function mountTree(locale: 'en' | 'fr') {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(StepHierarchyTree, {
    global: { plugins: [i18n] },
    props: { tree: FERNITE_CARBIDE_TREE, steps: [] },
  })
}

describe('StepHierarchyTree', () => {
  it.each([
    ['en', 'see above'],
    ['fr', 'voir plus haut'],
  ] as const)('shows each other consumer of a shared intermediate a reference row with the quantity it consumes (%s)', (locale, seeAbove) => {
    const wrapper = mountTree(locale)

    const referenceRows = wrapper.findAll('[data-testid="tree-material-reference"]')

    expect(referenceRows).toHaveLength(2)
    expect(referenceRows[0].text()).toContain('Hydrogen Fuel Block')
    expect(referenceRows[0].text()).toContain('×5')
    expect(referenceRows[0].text()).toContain(seeAbove)
    expect(referenceRows[1].text()).toContain('Hydrogen Fuel Block')
    expect(referenceRows[1].text()).toContain('×4')
    expect(referenceRows[1].text()).toContain(seeAbove)
  })

  it('shows the node of a shared intermediate once, under its first consumer', () => {
    const wrapper = mountTree('en')

    expect(wrapper.text().split('Hydrogen Isotopes')).toHaveLength(2)
    expect(wrapper.text().split('Hydrogen Fuel Block')).toHaveLength(4)
  })
})
