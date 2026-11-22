import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectSetupSourcing from '@/components/group-industry/ProjectSetupSourcing.vue'
import type { SourcingPicks } from '@/components/group-industry/projectSetup'
import type { SourcingDecisions } from '@/stores/group-industry/types'
import {
  BASE_TYPE_ID,
  COMPONENT_TYPE_ID,
  COMPONENTS_RECOMMENDED_ID,
  REACTION_TYPE_ID,
  sourcingDecisions,
  sourcingOption,
  sourcingRow,
  STATION_NAMES,
} from '../../projectSetupFixtures'

const RAW_TRANSLATION_KEY = /groupIndustry\.[a-zA-Z.]+/
const BASELINE_TOTAL = 1000

function mountSourcing(picks: SourcingPicks, decisions: SourcingDecisions = sourcingDecisions(BASELINE_TOTAL)): VueWrapper {
  return mount(ProjectSetupSourcing, {
    props: { decisions, picks, baselineTotal: BASELINE_TOTAL, stationNames: STATION_NAMES },
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
}

function byTestId(wrapper: VueWrapper, testId: string) {
  return wrapper.find(`[data-testid="${testId}"]`)
}

function textOf(wrapper: VueWrapper, testId: string): string {
  const element = byTestId(wrapper, testId)
  return element.exists() ? element.text() : ''
}

function attributeOf(wrapper: VueWrapper, testId: string, name: string): string | undefined {
  const element = byTestId(wrapper, testId)
  return element.exists() ? element.attributes(name) : 'element-absent'
}

async function click(wrapper: VueWrapper, testId: string): Promise<void> {
  const element = byTestId(wrapper, testId)
  if (element.exists()) await element.trigger('click')
}

function isShown(wrapper: VueWrapper, testId: string): boolean {
  const element = byTestId(wrapper, testId)
  return element.exists() && element.isVisible()
}

enableAutoUnmount(afterEach)

describe('ProjectSetupSourcing', () => {
  it('marks the capital cell red with the quantity on sale when it cannot cover the need and checks the chosen option of each line', () => {
    const wrapper = mountSourcing({})
    const rowText = textOf(wrapper, `sourcing-row-${COMPONENT_TYPE_ID}`)

    expect({
      ineligible: [
        attributeOf(wrapper, `sourcing-cell-${COMPONENT_TYPE_ID}-structure`, 'data-ineligible'),
        attributeOf(wrapper, `sourcing-cell-${COMPONENT_TYPE_ID}-jita`, 'data-ineligible'),
        attributeOf(wrapper, `sourcing-cell-${REACTION_TYPE_ID}-structure`, 'data-ineligible'),
      ],
      quantityOnSale: textOf(wrapper, `sourcing-cell-${COMPONENT_TYPE_ID}-structure`).includes('140'),
      selected: [
        attributeOf(wrapper, `sourcing-cell-${COMPONENT_TYPE_ID}-build`, 'data-selected'),
        attributeOf(wrapper, `sourcing-cell-${COMPONENT_TYPE_ID}-jita`, 'data-selected'),
        attributeOf(wrapper, `sourcing-cell-${REACTION_TYPE_ID}-jita`, 'data-selected'),
        attributeOf(wrapper, `sourcing-cell-${REACTION_TYPE_ID}-build`, 'data-selected'),
      ],
      nameAndStation: [rowText.includes('Capital Propulsion Engine'), rowText.includes(STATION_NAMES[COMPONENTS_RECOMMENDED_ID])],
      rawKey: RAW_TRANSLATION_KEY.test(wrapper.text()),
    }).toEqual({
      ineligible: ['true', undefined, undefined],
      quantityOnSale: true,
      selected: ['true', undefined, 'true', undefined],
      nameAndStation: [true, true],
      rawKey: false,
    })
  })

  it('folds the base materials until they are expanded, emits the picks on a click and summarises the delivered cost against the first answer', async () => {
    const wrapper = mountSourcing({ [REACTION_TYPE_ID]: 'structure' })

    const baseShownBefore = isShown(wrapper, `sourcing-row-${BASE_TYPE_ID}`)
    await click(wrapper, 'sourcing-base-toggle')
    const baseShownAfter = isShown(wrapper, `sourcing-row-${BASE_TYPE_ID}`)
    await click(wrapper, `sourcing-cell-${COMPONENT_TYPE_ID}-jita`)

    expect({
      baseShown: [baseShownBefore, baseShownAfter],
      emitted: wrapper.emitted('update:picks'),
      total: textOf(wrapper, 'sourcing-total').includes('950'),
      gap: /[-−]\s?50/.test(textOf(wrapper, 'sourcing-gap')),
      jita: textOf(wrapper, 'sourcing-jita').includes('80'),
      structure: textOf(wrapper, 'sourcing-structure').includes('150'),
      rawKey: RAW_TRANSLATION_KEY.test(wrapper.text()),
    }).toEqual({
      baseShown: [false, true],
      emitted: [[{ [REACTION_TYPE_ID]: 'structure', [COMPONENT_TYPE_ID]: 'jita' }]],
      total: true,
      gap: true,
      jita: true,
      structure: true,
      rawKey: false,
    })
  })

  it('shows the reused surplus per unit cost and the saving in the build cell of the line, and nothing on a line without them', () => {
    const withSurplus = mountSourcing({}, sourcingDecisions(BASELINE_TOTAL, [
      sourcingRow(COMPONENT_TYPE_ID, {
        options: {
          build: sourcingOption({ total: 1000, perUnit: 10, perUnitIfSurplusReused: 8.33 }),
          jita: sourcingOption({ total: 950, perUnit: 9.5 }),
        },
        recommended: 'jita',
        chosen: 'jita',
        savingIfSurplusReused: 116666.67,
      }),
    ]))
    const withoutSurplus = mountSourcing({}, sourcingDecisions(BASELINE_TOTAL, [sourcingRow(COMPONENT_TYPE_ID)]))
    const buildCell = `sourcing-cell-${COMPONENT_TYPE_ID}-build`

    expect({
      reusedLine: [byTestId(withSurplus, `sourcing-reused-${COMPONENT_TYPE_ID}`).exists(), /8[.,]33/.test(textOf(withSurplus, `sourcing-reused-${COMPONENT_TYPE_ID}`))],
      savingLine: [byTestId(withSurplus, `sourcing-reused-saving-${COMPONENT_TYPE_ID}`).exists(), /116[.,]67/.test(textOf(withSurplus, `sourcing-reused-saving-${COMPONENT_TYPE_ID}`))],
      insideTheBuildCell: [
        byTestId(withSurplus, buildCell).find(`[data-testid="sourcing-reused-${COMPONENT_TYPE_ID}"]`).exists(),
        byTestId(withSurplus, buildCell).find(`[data-testid="sourcing-reused-saving-${COMPONENT_TYPE_ID}"]`).exists(),
      ],
      jitaCellHasNone: byTestId(withSurplus, `sourcing-cell-${COMPONENT_TYPE_ID}-jita`).find(`[data-testid^="sourcing-reused"]`).exists(),
      withoutSurplus: [byTestId(withoutSurplus, `sourcing-reused-${COMPONENT_TYPE_ID}`).exists(), byTestId(withoutSurplus, `sourcing-reused-saving-${COMPONENT_TYPE_ID}`).exists()],
      rawKey: RAW_TRANSLATION_KEY.test(withSurplus.text()),
    }).toEqual({
      reusedLine: [true, true],
      savingLine: [true, true],
      insideTheBuildCell: [true, true],
      jitaCellHasNone: false,
      withoutSurplus: [false, false],
      rawKey: false,
    })
  })

  it('shows the reused per unit cost without the saving line when the reused surplus does not beat the purchase', () => {
    const wrapper = mountSourcing({}, sourcingDecisions(BASELINE_TOTAL, [
      sourcingRow(COMPONENT_TYPE_ID, {
        options: {
          build: sourcingOption({ total: 1200, perUnit: 12, perUnitIfSurplusReused: 10 }),
          jita: sourcingOption({ total: 950, perUnit: 9.5 }),
        },
        savingIfSurplusReused: null,
      }),
    ]))

    expect({
      reusedLine: byTestId(wrapper, `sourcing-reused-${COMPONENT_TYPE_ID}`).exists(),
      savingLine: byTestId(wrapper, `sourcing-reused-saving-${COMPONENT_TYPE_ID}`).exists(),
    }).toEqual({ reusedLine: true, savingLine: false })
  })

  it('marks the Jita price of the line with its coverage when the order book does not cover the quantity, and shows no marker when it does', () => {
    const partial = mountSourcing({}, sourcingDecisions(BASELINE_TOTAL, [
      sourcingRow(COMPONENT_TYPE_ID, { options: { build: sourcingOption(), jita: sourcingOption({ coveragePercent: 37.5 }) } }),
    ]))
    const covered = mountSourcing({}, sourcingDecisions(BASELINE_TOTAL, [
      sourcingRow(COMPONENT_TYPE_ID, { options: { build: sourcingOption(), jita: sourcingOption({ coveragePercent: null }) } }),
    ]))
    const jitaCell = `sourcing-cell-${COMPONENT_TYPE_ID}-jita`
    const buildCell = `sourcing-cell-${COMPONENT_TYPE_ID}-build`

    expect({
      partialJita: textOf(partial, jitaCell).includes('37.5'),
      partialBuild: textOf(partial, buildCell).includes('37.5'),
      coveredJita: textOf(covered, jitaCell).includes('%'),
      rawKey: RAW_TRANSLATION_KEY.test(partial.text()),
    }).toEqual({ partialJita: true, partialBuild: false, coveredJita: false, rawKey: false })
  })

  it('adds the volume of one unit to the Jita cell detail, and nothing at all when the volume is unknown rather than zero', () => {
    const known = mountSourcing({}, sourcingDecisions(BASELINE_TOTAL, [sourcingRow(COMPONENT_TYPE_ID, { volumePerUnit: 12.5 })]))
    const unknown = mountSourcing({}, sourcingDecisions(BASELINE_TOTAL, [sourcingRow(COMPONENT_TYPE_ID, { volumePerUnit: null })]))
    const volume = `sourcing-volume-${COMPONENT_TYPE_ID}`

    expect({
      known: [byTestId(known, volume).exists(), textOf(known, volume).includes('12.5')],
      insideTheJitaCell: byTestId(known, `sourcing-cell-${COMPONENT_TYPE_ID}-jita`).find(`[data-testid="${volume}"]`).exists(),
      unknown: byTestId(unknown, volume).exists(),
      rawKey: RAW_TRANSLATION_KEY.test(known.text()),
    }).toEqual({ known: [true, true], insideTheJitaCell: true, unknown: false, rawKey: false })
  })

  it('warns in the first cell of the line that the choice changes without the import, and not when it stays the same', () => {
    const changing = mountSourcing({}, sourcingDecisions(BASELINE_TOTAL, [sourcingRow(COMPONENT_TYPE_ID, { choiceChangesWithoutImport: true })]))
    const stable = mountSourcing({}, sourcingDecisions(BASELINE_TOTAL, [sourcingRow(COMPONENT_TYPE_ID, { choiceChangesWithoutImport: false })]))
    const warning = `sourcing-without-import-${COMPONENT_TYPE_ID}`

    expect({
      changing: byTestId(changing, warning).exists(),
      inTheFirstCell: byTestId(changing, `sourcing-row-${COMPONENT_TYPE_ID}`).find('td:first-child').find(`[data-testid="${warning}"]`).exists(),
      stable: byTestId(stable, warning).exists(),
      rawKey: RAW_TRANSLATION_KEY.test(changing.text()),
    }).toEqual({ changing: true, inTheFirstCell: true, stable: false, rawKey: false })
  })
})
