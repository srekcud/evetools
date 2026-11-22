import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectSetupSourcing from '@/components/group-industry/ProjectSetupSourcing.vue'
import type { SourcingPicks } from '@/components/group-industry/projectSetup'
import {
  BASE_TYPE_ID,
  COMPONENT_TYPE_ID,
  COMPONENTS_RECOMMENDED_ID,
  REACTION_TYPE_ID,
  sourcingDecisions,
  STATION_NAMES,
} from '../../projectSetupFixtures'

const RAW_TRANSLATION_KEY = /groupIndustry\.[a-zA-Z.]+/
const BASELINE_TOTAL = 1000

function mountSourcing(picks: SourcingPicks): VueWrapper {
  return mount(ProjectSetupSourcing, {
    props: { decisions: sourcingDecisions(BASELINE_TOTAL), picks, baselineTotal: BASELINE_TOTAL, stationNames: STATION_NAMES },
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
})
