import { DOMWrapper, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ShoppingTotalsSummary from '@/components/industry/shopping/ShoppingTotalsSummary.vue'
import type { ShoppingListTotals } from '@/stores/industry/types'

const NITROGEN_FUEL_BLOCK_TOTALS_WITH_SIX_UNPRICED_TYPES: ShoppingListTotals = {
  jita: null,
  import: 497_550,
  jitaWithImport: null,
  structure: null,
  volume: 414.625,
  best: null,
  savingsVsJitaWithImport: 0,
  savingsVsStructure: 40_500,
}

const NITROGEN_FUEL_BLOCK_TOTALS_WITH_ROBOTICS_ONLY_IN_THE_STRUCTURE: ShoppingListTotals = {
  jita: null,
  import: 497_550,
  jitaWithImport: null,
  structure: null,
  volume: 414.625,
  best: 838_750,
  savingsVsJitaWithImport: 0,
  savingsVsStructure: 0,
}

type MissingTotals = InstanceType<typeof ShoppingTotalsSummary>['$props']['missingTotals']

type SummaryTotals = { shoppingTotals: ShoppingListTotals; hasAnyStock: boolean; missingTotals: MissingTotals }

const MISSING_TOTALS_WITHOUT_STOCK: MissingTotals = { price: 0, volume: 0, jita: 0, jitaWeighted: null, structure: 0, savings: 0 }

const MISSING_TOTALS_WITH_A_MISSING_MATERIAL_WITHOUT_PRICE: MissingTotals = { price: null, volume: 120.5, jita: null, jitaWeighted: null, structure: null, savings: 0 }

function mountSummary({ shoppingTotals, hasAnyStock, missingTotals }: SummaryTotals, locale: 'en' | 'fr') {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ShoppingTotalsSummary, {
    global: { plugins: [i18n] },
    props: {
      shoppingTotals,
      missingTotals,
      hasAnyStock,
      jitaMultibuyFormat: '',
      structureMultibuyFormat: '',
      hasJitaItems: false,
      hasStructureItems: false,
    },
  })
  return { wrapper, t: i18n.global.t }
}

function totalCell(wrapper: VueWrapper, label: string): DOMWrapper<Element> {
  const labelElement = wrapper.findAll('div').find(element => element.text() === label)
  expect(labelElement, `total ${label}`).toBeDefined()
  return new DOMWrapper(labelElement!.element.parentElement!)
}

function expectUnknownAmount(cell: DOMWrapper<Element>, unknownValueTitle: string): void {
  expect(cell.get(`[title="${unknownValueTitle}"]`).text()).toBe('—')
  expect(cell.text()).not.toMatch(/\d/)
}

describe('ShoppingTotalsSummary with materials without a price', () => {
  it.each([
    ['en', 'Unknown value'],
    ['fr', 'Valeur inconnue'],
  ] as const)('shows the Jita + import, structure and best totals as unknown, never as 0 (%s)', (locale, unknownValueTitle) => {
    const { wrapper, t } = mountSummary({ shoppingTotals: NITROGEN_FUEL_BLOCK_TOTALS_WITH_SIX_UNPRICED_TYPES, hasAnyStock: false, missingTotals: MISSING_TOTALS_WITHOUT_STOCK }, locale)

    expectUnknownAmount(totalCell(wrapper, t('industry.shoppingTab.jitaImport')), unknownValueTitle)
    expectUnknownAmount(totalCell(wrapper, t('industry.shoppingTab.structure')), unknownValueTitle)
    expectUnknownAmount(totalCell(wrapper, t('industry.shoppingTab.bestTotal')), unknownValueTitle)
  })

  it('shows a known best total next to unknown Jita + import and structure totals', () => {
    const { wrapper, t } = mountSummary({ shoppingTotals: NITROGEN_FUEL_BLOCK_TOTALS_WITH_ROBOTICS_ONLY_IN_THE_STRUCTURE, hasAnyStock: false, missingTotals: MISSING_TOTALS_WITHOUT_STOCK }, 'en')

    const bestTotal = totalCell(wrapper, t('industry.shoppingTab.bestTotal'))
    expect(bestTotal.text()).toContain('838.75 K')
    expect(bestTotal.find('[title="Unknown value"]').exists()).toBe(false)
    expectUnknownAmount(totalCell(wrapper, t('industry.shoppingTab.jitaImport')), 'Unknown value')
    expectUnknownAmount(totalCell(wrapper, t('industry.shoppingTab.structure')), 'Unknown value')
  })

  it.each([
    ['en', 'Unknown value'],
    ['fr', 'Valeur inconnue'],
  ] as const)('shows the totals of the missing materials as unknown when a missing material has no price (%s)', (locale, unknownValueTitle) => {
    const { wrapper, t } = mountSummary({ shoppingTotals: NITROGEN_FUEL_BLOCK_TOTALS_WITH_SIX_UNPRICED_TYPES, hasAnyStock: true, missingTotals: MISSING_TOTALS_WITH_A_MISSING_MATERIAL_WITHOUT_PRICE }, locale)

    expectUnknownAmount(totalCell(wrapper, t('industry.shoppingTab.jitaImport')), unknownValueTitle)
    expectUnknownAmount(totalCell(wrapper, t('industry.shoppingTab.structure')), unknownValueTitle)
    expectUnknownAmount(totalCell(wrapper, t('industry.shoppingTab.bestTotal')), unknownValueTitle)
  })
})
