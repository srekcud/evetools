import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import InventionOptionsPanel from '@/components/industry/profit/InventionOptionsPanel.vue'
import type { ProfitMarginInventionOption } from '@/stores/industry/types'

const margins: Record<string, string> = en.industry.margins
const frenchMargins: Record<string, string> = fr.industry.margins
const NEW_LABEL_KEYS = ['attemptsCol', 'decryptorCostCol', 'costPerUnitCol', 'deltaPerUnitCol', 'datacoresCostPerAttempt', 'probabilityWithoutSkills']
const UNKNOWN_LABEL = en.industry.inventionUnknown.label
const NO_DECRYPTOR_LABEL = en.industry.bpcKitTab.none

const NO_DECRYPTOR_OPTION: ProfitMarginInventionOption = {
  decryptorTypeId: null,
  decryptorName: 'No Decryptor',
  me: 2,
  te: 4,
  runs: 10,
  probability: 0.34,
  materialCost: 8_000,
  jobInstallCost: 1_000,
  inventionCost: 2_400,
  totalProductionCost: 11_400,
  bestMargin: 12,
  expectedAttempts: 0.2941,
  decryptorCost: 0,
  costPerUnit: 1_140,
  costPerUnitDelta: 0,
}

const PROCESS_OPTION: ProfitMarginInventionOption = {
  decryptorTypeId: 34205,
  decryptorName: 'Process Decryptor',
  me: 5,
  te: 10,
  runs: 10,
  probability: 0.374,
  materialCost: 7_000,
  jobInstallCost: 1_000,
  inventionCost: 2_600,
  totalProductionCost: 10_600,
  bestMargin: 15,
  expectedAttempts: 0.2674,
  decryptorCost: 342_050,
  costPerUnit: 1_060,
  costPerUnitDelta: -80,
}

const PARITY_OPTION_COSTLIER: ProfitMarginInventionOption = {
  ...PROCESS_OPTION,
  decryptorTypeId: 34204,
  decryptorName: 'Parity Decryptor',
  costPerUnit: 1_300,
  costPerUnitDelta: 160,
}

const UNPRICED_OPTION: ProfitMarginInventionOption = {
  ...PROCESS_OPTION,
  decryptorTypeId: 34203,
  decryptorName: 'Augmentation Decryptor',
  expectedAttempts: null,
  decryptorCost: null,
  costPerUnit: null,
  costPerUnitDelta: null,
}

function mountPanel(locale: 'en' | 'fr' = 'en') {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(InventionOptionsPanel, {
    global: { plugins: [i18n] },
    props: {
      baseProbability: 0.34,
      unknownReason: null,
      datacores: ['Datacore A', 'Datacore B'],
      options: [NO_DECRYPTOR_OPTION, PROCESS_OPTION, PARITY_OPTION_COSTLIER, UNPRICED_OPTION],
      bestDecryptorIndex: 1,
      selectedOption: null,
      selectedDecryptorTypeId: undefined,
      effectiveInventionCost: 2_600,
      effectiveTotalCost: 10_600,
      bestMargin: 15,
      datacoresCostPerAttempt: 460,
    },
  })
}

function cellsOf(wrapper: ReturnType<typeof mountPanel>, decryptorName: string): string[] {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.text().includes(decryptorName))
  expect(row, `row ${decryptorName}`).toBeDefined()
  return row!.findAll('td').map(cell => cell.text())
}

const ATTEMPTS_CELL = 6
const DECRYPTOR_COST_CELL = 7
const COST_PER_UNIT_CELL = 8
const DELTA_CELL = 9

describe('InventionOptionsPanel comparison columns', () => {
  it.each(NEW_LABEL_KEYS)('has the label %s in English and in French', key => {
    expect(margins[key], `en ${key}`).toEqual(expect.stringMatching(/\S/))
    expect(frenchMargins[key], `fr ${key}`).toEqual(expect.stringMatching(/\S/))
  })

  it('shows the expected attempts of each option with one decimal', () => {
    const wrapper = mountPanel()

    expect(cellsOf(wrapper, NO_DECRYPTOR_LABEL)[ATTEMPTS_CELL]).toBe('0.3')
    expect(cellsOf(wrapper, 'Process Decryptor')[ATTEMPTS_CELL]).toBe('0.3')
  })

  it('shows the decryptor price per attempt, none for the option without decryptor', () => {
    const wrapper = mountPanel()

    expect(cellsOf(wrapper, 'Process Decryptor')[DECRYPTOR_COST_CELL]).toBe('342.05 K')
    expect(cellsOf(wrapper, NO_DECRYPTOR_LABEL)[DECRYPTOR_COST_CELL]).toBe('0')
  })

  it('shows the cost per unit and its signed delta against no decryptor, a dash for the baseline', () => {
    const wrapper = mountPanel()

    expect(cellsOf(wrapper, 'Process Decryptor')[COST_PER_UNIT_CELL]).toBe('1.06 K')
    expect(cellsOf(wrapper, 'Process Decryptor')[DELTA_CELL]).toBe('-80')
    expect(cellsOf(wrapper, 'Parity Decryptor')[DELTA_CELL]).toBe('+160')
    expect(cellsOf(wrapper, NO_DECRYPTOR_LABEL)[DELTA_CELL]).toBe('—')
  })

  it('shows an unknown attempts, price, cost and delta as unknown, never as 0', () => {
    const wrapper = mountPanel()

    const cells = cellsOf(wrapper, 'Augmentation Decryptor')
    expect([cells[ATTEMPTS_CELL], cells[DECRYPTOR_COST_CELL], cells[COST_PER_UNIT_CELL]]).toEqual([UNKNOWN_LABEL, UNKNOWN_LABEL, UNKNOWN_LABEL])
    expect(cells[DELTA_CELL]).toBe('—')
  })

  it('shows the datacores cost per attempt in the header', () => {
    const wrapper = mountPanel()

    expect(wrapper.text()).toContain(margins.datacoresCostPerAttempt)
    expect(wrapper.text()).toContain('460')
  })

  it('says in the probability column header that the skills are not applied', () => {
    const wrapper = mountPanel()

    expect(wrapper.find('thead').text()).toContain(margins.probabilityWithoutSkills)
  })
})
