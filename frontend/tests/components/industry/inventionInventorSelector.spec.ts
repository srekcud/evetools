import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import InventionOptionsPanel from '@/components/industry/profit/InventionOptionsPanel.vue'
import type { InventionUnknownReason, ProfitMarginInventionOption } from '@/stores/industry/types'

const MAIN_ID = '0192f5a0-7c1e-7d2a-9b4e-3a5c6d7e8f90'
const ALT_ID = '0192f5a0-7c1e-7d2a-9b4e-3a5c6d7e8faa'
const CHARACTERS = [
  { id: MAIN_ID, name: 'Pilot Prime' },
  { id: ALT_ID, name: 'Pilot Alt' },
]
const MISSING_INVENTOR_SKILLS: InventionUnknownReason = 'missing_inventor_skills'
const margins: Record<string, string> = en.industry.margins
const frenchMargins: Record<string, string> = fr.industry.margins
const unknownReasons: Record<string, string> = en.industry.inventionUnknown
const frenchUnknownReasons: Record<string, string> = fr.industry.inventionUnknown

const OPTION: ProfitMarginInventionOption = {
  decryptorTypeId: null,
  decryptorName: 'No Decryptor',
  me: 2,
  te: 4,
  runs: 10,
  probability: 0.4958,
  inventionCost: 2_400,
  totalProductionCost: 11_400,
  bestMargin: 12,
  expectedAttempts: 0.2,
  decryptorCost: 0,
  costPerUnit: 1_140,
  costPerUnitDelta: 0,
}

const UNKNOWN_SKILLS_OPTION: ProfitMarginInventionOption = {
  ...OPTION,
  probability: null,
  inventionCost: null,
  totalProductionCost: null,
  bestMargin: null,
  expectedAttempts: null,
  costPerUnit: null,
  costPerUnitDelta: null,
}

function mountPanel(overrides: Record<string, unknown> = {}, locale: 'en' | 'fr' = 'en') {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(InventionOptionsPanel, {
    global: { plugins: [i18n] },
    props: {
      baseProbability: 0.34,
      unknownReason: null,
      datacores: ['Datacore A'],
      datacoresCostPerAttempt: 460,
      options: [OPTION],
      bestDecryptorIndex: 0,
      selectedDecryptorTypeId: undefined,
      effectiveInventionCost: 2_400,
      effectiveTotalCost: 11_400,
      bestMargin: 12,
      inventors: CHARACTERS,
      selectedInventorId: MAIN_ID,
      ...overrides,
    },
  })
}

describe('InventionOptionsPanel inventor selector', () => {
  it.each(['inventorLabel', 'withoutSkills'])('has the label %s in English and in French', key => {
    expect(margins[key], `en ${key}`).toEqual(expect.stringMatching(/\S/))
    expect(frenchMargins[key], `fr ${key}`).toEqual(expect.stringMatching(/\S/))
  })

  it('offers no skills first, then every character of the user', () => {
    const wrapper = mountPanel()

    const options = wrapper.findAll('select[data-test="inventor-select"] option')
    expect(options.map(option => option.text())).toEqual([margins.withoutSkills, 'Pilot Prime', 'Pilot Alt'])
  })

  it('selects the chosen character', () => {
    const wrapper = mountPanel({ selectedInventorId: ALT_ID })

    expect((wrapper.find('select[data-test="inventor-select"]').element as HTMLSelectElement).value).toBe(ALT_ID)
  })

  it('selects no skills when no character is chosen', () => {
    const wrapper = mountPanel({ selectedInventorId: null })

    expect((wrapper.find('select[data-test="inventor-select"]').element as HTMLSelectElement).value).toBe('')
  })

  it('emits the id of the chosen character, null for no skills', async () => {
    const wrapper = mountPanel()
    const select = wrapper.find('select[data-test="inventor-select"]')

    await select.setValue(ALT_ID)
    await select.setValue('')

    expect(wrapper.emitted('select-inventor')).toEqual([[ALT_ID], [null]])
  })

  it('says the probability is without skills only when no character is chosen', () => {
    const withoutCharacter = mountPanel({ selectedInventorId: null })
    const withCharacter = mountPanel({ selectedInventorId: MAIN_ID })

    expect(withoutCharacter.find('thead').text()).toContain(margins.probabilityWithoutSkills)
    expect(withCharacter.find('thead').text()).not.toContain(margins.probabilityWithoutSkills)
  })

  it.each([
    ['en', unknownReasons],
    ['fr', frenchUnknownReasons],
  ] as const)('explains that the skills of the character are unknown (%s)', (locale, labels) => {
    const wrapper = mountPanel({ unknownReason: MISSING_INVENTOR_SKILLS, options: [UNKNOWN_SKILLS_OPTION] }, locale)

    expect(labels[MISSING_INVENTOR_SKILLS]).toEqual(expect.stringMatching(/\S/))
    expect(wrapper.text()).toContain(labels[MISSING_INVENTOR_SKILLS])
    expect(wrapper.text()).not.toContain(`industry.inventionUnknown.${MISSING_INVENTOR_SKILLS}`)
  })

  it('shows the probability of the unknown skills as unknown, never as 0', () => {
    const wrapper = mountPanel({ unknownReason: MISSING_INVENTOR_SKILLS, options: [UNKNOWN_SKILLS_OPTION] })

    const cells = wrapper.findAll('tbody tr')[0]!.findAll('td').map(cell => cell.text())
    expect(cells[5]).toBe(en.industry.inventionUnknown.label)
  })
})
