import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import InventionOptionsPanel from '@/components/industry/profit/InventionOptionsPanel.vue'
import type { InventionUnknownReason, ProfitMarginInventionOption } from '@/stores/industry/types'

const MISSING_MARKET_PRICE: InventionUnknownReason = 'missing_market_price'

const NO_DECRYPTOR_OPTION: ProfitMarginInventionOption = {
  decryptorTypeId: null,
  decryptorName: 'No Decryptor',
  me: 2,
  te: 4,
  runs: 10,
  probability: 0.34,
  inventionCost: 2400,
  totalProductionCost: 11_400,
  bestMargin: 373.7,
}

const PARITY_OPTION_WITHOUT_PRICE: ProfitMarginInventionOption = {
  decryptorTypeId: 34204,
  decryptorName: 'Parity Decryptor',
  me: 3,
  te: 2,
  runs: 13,
  probability: 0.51,
  inventionCost: null,
  totalProductionCost: null,
  bestMargin: null,
}

function mountPanelWithUnknownParityPrice(locale: 'en' | 'fr') {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(InventionOptionsPanel, {
    global: { plugins: [i18n] },
    props: {
      baseProbability: 0.34,
      unknownReason: MISSING_MARKET_PRICE,
      datacores: ['Datacore - Mechanical Engineering', 'Datacore - Minmatar Starship Engineering'],
      options: [NO_DECRYPTOR_OPTION, PARITY_OPTION_WITHOUT_PRICE],
      bestDecryptorIndex: 0,
      selectedOption: PARITY_OPTION_WITHOUT_PRICE,
      selectedDecryptorTypeId: 34204,
      effectiveInventionCost: null,
      effectiveTotalCost: null,
      bestMargin: null,
    },
  })
}

function rowOf(wrapper: ReturnType<typeof mountPanelWithUnknownParityPrice>, decryptorName: string) {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.text().includes(decryptorName))
  expect(row, `row ${decryptorName}`).toBeDefined()
  return row!
}

describe('InventionOptionsPanel with a decryptor without market price', () => {
  it.each([
    ['en', en],
    ['fr', fr],
  ] as const)('defines a missing market price label (%s)', (_locale, messages) => {
    const unknownReasonLabels: Record<string, string> = messages.industry.inventionUnknown

    expect(unknownReasonLabels[MISSING_MARKET_PRICE]).toEqual(expect.stringMatching(/\S/))
  })

  it.each([
    ['en', en],
    ['fr', fr],
  ] as const)('shows the missing market price reason instead of the raw key (%s)', (locale, messages) => {
    const wrapper = mountPanelWithUnknownParityPrice(locale)
    const unknownReasonLabels: Record<string, string> = messages.industry.inventionUnknown

    expect(wrapper.text()).not.toContain(`industry.inventionUnknown.${MISSING_MARKET_PRICE}`)
    expect(wrapper.text()).toContain(unknownReasonLabels[MISSING_MARKET_PRICE])
  })

  it.each([
    ['en', 'Unknown'],
    ['fr', 'Inconnu'],
  ] as const)('shows the invention cost, production cost and margin of the unpriced option as unknown, never as 0 (%s)', (locale, unknownLabel) => {
    const wrapper = mountPanelWithUnknownParityPrice(locale)

    const parityCells = rowOf(wrapper, 'Parity Decryptor').findAll('td').map(cell => cell.text())
    expect(parityCells.slice(-3)).toEqual([unknownLabel, unknownLabel, unknownLabel])
  })
})
