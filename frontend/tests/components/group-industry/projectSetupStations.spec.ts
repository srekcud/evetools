import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectSetupStations from '@/components/group-industry/ProjectSetupStations.vue'
import type { StationPicks } from '@/components/group-industry/projectSetup'
import type { ProjectWorkStations } from '@/stores/group-industry/types'
import {
  COMPONENTS_ALTERNATIVE_ID,
  COMPONENTS_RECOMMENDED_ID,
  componentsType,
  projectWorkStations,
  REACTION_ALTERNATIVE_ID,
  REACTION_RECOMMENDED_ID,
  reactionType,
  stationRow,
  STATION_NAMES,
  unknownAmountsRow,
} from '../../projectSetupFixtures'

const RAW_TRANSLATION_KEY = /groupIndustry\.[a-zA-Z.]+/
const STANDALONE_ZERO = /(^|[^\d.,])0([^\d.,]|$)/

function mountStations(workStations: ProjectWorkStations, picks: StationPicks): VueWrapper {
  return mount(ProjectSetupStations, {
    props: { workStations, picks },
    global: { plugins: [createI18n({ legacy: false, locale: 'fr', messages: { en, fr } })] },
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

function isChecked(wrapper: VueWrapper, testId: string): boolean {
  const radio = byTestId(wrapper, testId)
  return radio.exists() && (radio.element as HTMLInputElement).checked
}

function digitsOf(text: string): string {
  return text.replace(/\D/g, '')
}

enableAutoUnmount(afterEach)

describe('ProjectSetupStations', () => {
  it('shows one card per candidate with the recommended badge, the picked radios checked and the summary of the picks', async () => {
    const wrapper = mountStations(projectWorkStations(), { reaction: REACTION_ALTERNATIVE_ID, components: COMPONENTS_ALTERNATIVE_ID })

    const checked = [
      isChecked(wrapper, `station-radio-reaction-${REACTION_RECOMMENDED_ID}`),
      isChecked(wrapper, `station-radio-reaction-${REACTION_ALTERNATIVE_ID}`),
      isChecked(wrapper, `station-radio-components-${COMPONENTS_RECOMMENDED_ID}`),
      isChecked(wrapper, `station-radio-components-${COMPONENTS_ALTERNATIVE_ID}`),
    ]
    await click(wrapper, `station-radio-reaction-${REACTION_RECOMMENDED_ID}`)

    expect({
      rows: [byTestId(wrapper, 'station-row-reaction').exists(), byTestId(wrapper, 'station-row-components').exists()],
      cards: [
        textOf(wrapper, `station-card-reaction-${REACTION_RECOMMENDED_ID}`).includes(STATION_NAMES[REACTION_RECOMMENDED_ID]),
        textOf(wrapper, `station-card-reaction-${REACTION_ALTERNATIVE_ID}`).includes(STATION_NAMES[REACTION_ALTERNATIVE_ID]),
        textOf(wrapper, `station-card-components-${COMPONENTS_RECOMMENDED_ID}`).includes(STATION_NAMES[COMPONENTS_RECOMMENDED_ID]),
        textOf(wrapper, `station-card-components-${COMPONENTS_ALTERNATIVE_ID}`).includes(STATION_NAMES[COMPONENTS_ALTERNATIVE_ID]),
      ],
      recommendedBadges: [
        byTestId(wrapper, `station-recommended-reaction-${REACTION_RECOMMENDED_ID}`).exists(),
        byTestId(wrapper, `station-recommended-reaction-${REACTION_ALTERNATIVE_ID}`).exists(),
        byTestId(wrapper, `station-recommended-components-${COMPONENTS_RECOMMENDED_ID}`).exists(),
        byTestId(wrapper, `station-recommended-components-${COMPONENTS_ALTERNATIVE_ID}`).exists(),
      ],
      checked,
      totalShows165: digitsOf(textOf(wrapper, 'stations-total')).includes('165'),
      stationsListed: [
        textOf(wrapper, 'stations-count').includes(STATION_NAMES[REACTION_ALTERNATIVE_ID]),
        textOf(wrapper, 'stations-count').includes(STATION_NAMES[COMPONENTS_ALTERNATIVE_ID]),
      ],
      emitted: wrapper.emitted('update:picks'),
      rawKey: RAW_TRANSLATION_KEY.test(wrapper.text()),
    }).toEqual({
      rows: [true, true],
      cards: [true, true, true, true],
      recommendedBadges: [true, false, true, false],
      checked: [false, true, false, true],
      totalShows165: true,
      stationsListed: [true, true],
      emitted: [[{ reaction: REACTION_RECOMMENDED_ID, components: COMPONENTS_ALTERNATIVE_ID }]],
      rawKey: false,
    })
  })

  it('disables the radios of a locked type with its reason and shows an unknown amount instead of zero', async () => {
    const workStations = projectWorkStations([
      reactionType({ rows: [stationRow(REACTION_RECOMMENDED_ID), unknownAmountsRow(REACTION_ALTERNATIVE_ID)] }),
      componentsType({ locked: true, lockReason: 'purchase_made' }),
    ])
    const wrapper = mountStations(workStations, { reaction: REACTION_ALTERNATIVE_ID })

    await click(wrapper, `station-radio-components-${COMPONENTS_ALTERNATIVE_ID}`)

    expect({
      lockedRadiosDisabled: [
        attributeOf(wrapper, `station-radio-components-${COMPONENTS_RECOMMENDED_ID}`, 'disabled') !== undefined,
        attributeOf(wrapper, `station-radio-components-${COMPONENTS_ALTERNATIVE_ID}`, 'disabled') !== undefined,
      ],
      freeRadioDisabled: attributeOf(wrapper, `station-radio-reaction-${REACTION_RECOMMENDED_ID}`, 'disabled') !== undefined,
      lockReasons: [byTestId(wrapper, 'station-lock-components').exists(), byTestId(wrapper, 'station-lock-reaction').exists()],
      emitted: wrapper.emitted('update:picks'),
      unknownCardShowsZero: STANDALONE_ZERO.test(textOf(wrapper, `station-card-reaction-${REACTION_ALTERNATIVE_ID}`)),
      totalShowsAnyNumber: /\d/.test(textOf(wrapper, 'stations-total')),
      gapShowsAnyNumber: /\d/.test(textOf(wrapper, 'stations-gap')),
      rawKey: RAW_TRANSLATION_KEY.test(wrapper.text()),
    }).toEqual({
      lockedRadiosDisabled: [true, true],
      freeRadioDisabled: false,
      lockReasons: [true, false],
      emitted: undefined,
      unknownCardShowsZero: false,
      totalShowsAnyNumber: false,
      gapShowsAnyNumber: false,
      rawKey: false,
    })
  })
})
