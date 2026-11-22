import { enableAutoUnmount, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils'
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
const ZERO_PERCENT = /(^|[^\d.,])0\s?%/

function mountStations(workStations: ProjectWorkStations, picks: StationPicks): VueWrapper {
  return mount(ProjectSetupStations, {
    props: { workStations, picks },
    global: { plugins: [createI18n({ legacy: false, locale: 'fr', messages: { en, fr } })], stubs: { RouterLink: RouterLinkStub } },
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

  it('shows the installation tax rate of each station and the tax not set warning with its link to the structure settings, never a 0 % rate', async () => {
    const workStations = projectWorkStations([
      reactionType({ rows: [stationRow(REACTION_RECOMMENDED_ID, { facilityTaxPercent: 0.25 }), stationRow(REACTION_ALTERNATIVE_ID, { facilityTaxPercent: null })] }),
      componentsType({ costsCompared: false, rows: [stationRow(COMPONENTS_RECOMMENDED_ID, { facilityTaxPercent: null }), stationRow(COMPONENTS_ALTERNATIVE_ID, { facilityTaxPercent: 1.5 })] }),
    ])
    const wrapper = mountStations(workStations, {})
    const link = wrapper.findComponent(RouterLinkStub)

    expect({
      setRateDigits: digitsOf(textOf(wrapper, `station-tax-reaction-${REACTION_RECOMMENDED_ID}`)),
      setRateNotWarned: byTestId(wrapper, `station-tax-not-set-reaction-${REACTION_RECOMMENDED_ID}`).exists(),
      unsetWarned: byTestId(wrapper, `station-tax-not-set-reaction-${REACTION_ALTERNATIVE_ID}`).exists(),
      unsetShowsRate: byTestId(wrapper, `station-tax-reaction-${REACTION_ALTERNATIVE_ID}`).exists(),
      unsetCardShowsZeroPercent: ZERO_PERCENT.test(textOf(wrapper, `station-card-reaction-${REACTION_ALTERNATIVE_ID}`)),
      linkTarget: link.exists() ? link.props('to') : 'link-absent',
      notComparedUnsetWarned: byTestId(wrapper, `station-tax-not-set-components-${COMPONENTS_RECOMMENDED_ID}`).exists(),
      notComparedSetRateDigits: digitsOf(textOf(wrapper, `station-tax-components-${COMPONENTS_ALTERNATIVE_ID}`)),
      rawKey: RAW_TRANSLATION_KEY.test(wrapper.text()),
    }).toEqual({
      setRateDigits: expect.stringContaining('025'),
      setRateNotWarned: false,
      unsetWarned: true,
      unsetShowsRate: false,
      unsetCardShowsZeroPercent: false,
      linkTarget: { name: 'settings', params: { section: 'industry' } },
      notComparedUnsetWarned: true,
      notComparedSetRateDigits: expect.stringContaining('15'),
      rawKey: false,
    })
  })
})
