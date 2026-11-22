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
const HULL_NOTICE_TEST_ID = 'hull-to-specify-notice'
const HULL_NOTICE_TITLE = { fr: 'Ces structures sont laissées de côté : coque à préciser', en: 'These structures are left out: hull to specify' } as const
const LEGACY_COMPLEX = { id: 'structure-legacy-complex', name: 'Perimeter Complex', structureType: 'engineering_complex' }
const LEGACY_REFINERY = { id: 'structure-legacy-refinery', name: 'Sotiyo Hek Refinery', structureType: 'refinery' }

type PolicyProps = {
  acceptsOutsideFavoriteSystem?: boolean
  minimumOutsideSaving?: number
  policyBusy?: boolean
  policyError?: string | null
}

function mountStations(workStations: ProjectWorkStations, picks: StationPicks, policy: PolicyProps = {}, locale: 'fr' | 'en' = 'fr'): VueWrapper {
  return mount(ProjectSetupStations, {
    props: { workStations, picks, acceptsOutsideFavoriteSystem: false, minimumOutsideSaving: 5, ...policy },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })], stubs: { RouterLink: RouterLinkStub } },
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

async function typeThreshold(wrapper: VueWrapper, value: string): Promise<void> {
  const input = byTestId(wrapper, 'stations-policy-threshold-input')
  if (input.exists()) await input.setValue(value)
}

async function setPolicyCheckbox(wrapper: VueWrapper, checked: boolean): Promise<void> {
  const checkbox = byTestId(wrapper, 'stations-policy-outside')
  if (checkbox.exists()) await checkbox.setValue(checked)
}

function valueOf(wrapper: VueWrapper, testId: string): string | null {
  const element = byTestId(wrapper, testId)
  return element.exists() ? (element.element as HTMLInputElement).value : null
}

function isDisabled(wrapper: VueWrapper, testId: string): boolean {
  return attributeOf(wrapper, testId, 'disabled') !== undefined
}

function bannerScenario(overrides: { retainedInFavoriteSystem?: boolean; cheapestInFavoriteSystem?: boolean; policy?: PolicyProps } = {}): VueWrapper {
  const workStations = projectWorkStations([
    reactionType({
      recommendedStructureId: REACTION_RECOMMENDED_ID,
      rows: [
        stationRow(REACTION_RECOMMENDED_ID, { totalCost: 900, inFavoriteSystem: overrides.cheapestInFavoriteSystem ?? false }),
        stationRow(REACTION_ALTERNATIVE_ID, { totalCost: 1000, inFavoriteSystem: overrides.retainedInFavoriteSystem ?? true }),
      ],
    }),
    componentsType(),
  ])
  return mountStations(workStations, { reaction: REACTION_ALTERNATIVE_ID }, overrides.policy)
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

  it('shows the policy block with the checkbox of the project, the threshold only while the policy accepts a station outside the favorite system, and no raw key', () => {
    const accepting = mountStations(projectWorkStations(), {}, { acceptsOutsideFavoriteSystem: true, minimumOutsideSaving: 5 })
    const refusing = mountStations(projectWorkStations(), {}, { acceptsOutsideFavoriteSystem: false, minimumOutsideSaving: 5 })

    expect({
      accepting: {
        block: byTestId(accepting, 'stations-policy').exists(),
        checked: isChecked(accepting, 'stations-policy-outside'),
        thresholdValue: valueOf(accepting, 'stations-policy-threshold-input'),
        saveDisabled: isDisabled(accepting, 'stations-policy-threshold-save'),
        rawKey: RAW_TRANSLATION_KEY.test(textOf(accepting, 'stations-policy')),
      },
      refusing: {
        block: byTestId(refusing, 'stations-policy').exists(),
        checked: isChecked(refusing, 'stations-policy-outside'),
        thresholdShown: byTestId(refusing, 'stations-policy-threshold-input').exists(),
        saveShown: byTestId(refusing, 'stations-policy-threshold-save').exists(),
        errorShown: byTestId(refusing, 'stations-policy-error').exists(),
        rawKey: RAW_TRANSLATION_KEY.test(textOf(refusing, 'stations-policy')),
      },
    }).toEqual({
      accepting: { block: true, checked: true, thresholdValue: '5', saveDisabled: true, rawKey: false },
      refusing: { block: true, checked: false, thresholdShown: false, saveShown: false, errorShown: false, rawKey: false },
    })
  })

  it('emits the policy change of the checkbox and of the threshold, a threshold of 0 included, and keeps the save button disabled while the draft is unchanged, negative, not a number or a request runs', async () => {
    const wrapper = mountStations(projectWorkStations(), {}, { acceptsOutsideFavoriteSystem: true, minimumOutsideSaving: 5 })
    const disabledAfter: Record<string, boolean> = {}
    for (const draft of ['5', '-1', '', '20']) {
      await typeThreshold(wrapper, draft)
      disabledAfter[draft] = isDisabled(wrapper, 'stations-policy-threshold-save')
    }
    await typeThreshold(wrapper, '0')
    await click(wrapper, 'stations-policy-threshold-save')
    await setPolicyCheckbox(wrapper, false)
    const unchecked = mountStations(projectWorkStations(), {}, { acceptsOutsideFavoriteSystem: false })
    await setPolicyCheckbox(unchecked, true)
    const busy = mountStations(projectWorkStations(), {}, { acceptsOutsideFavoriteSystem: true, minimumOutsideSaving: 5, policyBusy: true })
    await typeThreshold(busy, '20')

    expect({
      disabledAfter,
      zeroSaveEmitted: wrapper.emitted('update:policy'),
      checkedEmitted: unchecked.emitted('update:policy'),
      busySaveDisabled: isDisabled(busy, 'stations-policy-threshold-save'),
    }).toEqual({
      disabledAfter: { '5': true, '-1': true, '': true, '20': false },
      zeroSaveEmitted: [[{ minimumOutsideSaving: 0 }], [{ acceptsOutsideFavoriteSystem: false }]],
      checkedEmitted: [[{ acceptsOutsideFavoriteSystem: true }]],
      busySaveDisabled: true,
    })
  })

  it('puts the stored threshold back in the input when a policy change is refused', async () => {
    const wrapper = mountStations(projectWorkStations(), {}, { acceptsOutsideFavoriteSystem: true, minimumOutsideSaving: 5 })
    await typeThreshold(wrapper, '20')
    const draftBefore = valueOf(wrapper, 'stations-policy-threshold-input')

    await wrapper.setProps({ policyError: 'Le projet est terminé' })

    expect({
      draftBefore,
      afterRefusal: valueOf(wrapper, 'stations-policy-threshold-input'),
      saveDisabled: isDisabled(wrapper, 'stations-policy-threshold-save'),
    }).toEqual({ draftBefore: '20', afterRefusal: '5', saveDisabled: true })
  })

  it('shows the message of a refused policy change in an alert and nothing when there is none', () => {
    const refused = mountStations(projectWorkStations(), {}, { policyError: 'Le projet est terminé' })
    const quiet = mountStations(projectWorkStations(), {}, { policyError: null })

    expect({
      text: textOf(refused, 'stations-policy-error'),
      role: attributeOf(refused, 'stations-policy-error', 'role'),
      quiet: byTestId(quiet, 'stations-policy-error').exists(),
    }).toEqual({ text: 'Le projet est terminé', role: 'alert', quiet: false })
  })

  it('shows the position of each card: favorite system without a count, outside with its jumps in the singular or plural, and jumps unknown rather than 0 jump', () => {
    const workStations = projectWorkStations([
      reactionType({
        rows: [
          stationRow(REACTION_RECOMMENDED_ID, { inFavoriteSystem: true, jumps: 0 }),
          stationRow(REACTION_ALTERNATIVE_ID, { inFavoriteSystem: false, jumps: 2 }),
        ],
      }),
      componentsType({
        rows: [
          stationRow(COMPONENTS_RECOMMENDED_ID, { inFavoriteSystem: false, jumps: 1 }),
          stationRow(COMPONENTS_ALTERNATIVE_ID, { inFavoriteSystem: false, jumps: null }),
        ],
      }),
    ])
    const wrapper = mountStations(workStations, {})
    const position = (workType: string, structureId: string) => textOf(wrapper, `station-position-${workType}-${structureId}`)

    expect({
      favorite: { outsideWording: /hors/i.test(position('reaction', REACTION_RECOMMENDED_ID)), jumpCount: /saut/i.test(position('reaction', REACTION_RECOMMENDED_ID)), shown: position('reaction', REACTION_RECOMMENDED_ID) !== '' },
      twoJumps: { outsideWording: /hors/i.test(position('reaction', REACTION_ALTERNATIVE_ID)), plural: /(^|\D)2 sauts/.test(position('reaction', REACTION_ALTERNATIVE_ID)) },
      oneJump: { singular: /(^|\D)1 saut(?!s)/.test(position('components', COMPONENTS_RECOMMENDED_ID)) },
      unknown: {
        outsideWording: /hors/i.test(position('components', COMPONENTS_ALTERNATIVE_ID)),
        unknownJumps: /sauts inconnus/i.test(position('components', COMPONENTS_ALTERNATIVE_ID)),
        zeroJump: /(^|\D)0 saut/.test(position('components', COMPONENTS_ALTERNATIVE_ID)),
      },
      rawKey: RAW_TRANSLATION_KEY.test(wrapper.text()),
    }).toEqual({
      favorite: { outsideWording: false, jumpCount: false, shown: true },
      twoJumps: { outsideWording: true, plural: true },
      oneJump: { singular: true },
      unknown: { outsideWording: true, unknownJumps: true, zeroJump: false },
      rawKey: false,
    })
  })

  it.each(['fr', 'en'] as const)('lists every structure left out for its hull to specify under the title with the link to the structure settings (%s)', locale => {
    const wrapper = mountStations(projectWorkStations(undefined, [LEGACY_COMPLEX, LEGACY_REFINERY]), {}, {}, locale)
    const notice = byTestId(wrapper, HULL_NOTICE_TEST_ID)
    const link = notice.exists() ? notice.findComponent(RouterLinkStub) : null

    expect({
      title: notice.exists() ? notice.text().includes(HULL_NOTICE_TITLE[locale]) : null,
      names: notice.exists() ? [notice.text().includes(LEGACY_COMPLEX.name), notice.text().includes(LEGACY_REFINERY.name)] : null,
      linkTarget: link?.exists() ? link.props('to') : 'link-absent',
      rawKey: RAW_TRANSLATION_KEY.test(wrapper.text()),
    }).toEqual({
      title: true,
      names: [true, true],
      linkTarget: { name: 'settings', params: { section: 'industry' } },
      rawKey: false,
    })
  })

  it('shows a banner with the station and the saving for each reason, the action to allow or to go back to the favorite system, and no banner on a type without difference', async () => {
    const onlyFavorite = bannerScenario({ policy: { acceptsOutsideFavoriteSystem: false } })
    const belowThreshold = bannerScenario({ policy: { acceptsOutsideFavoriteSystem: true, minimumOutsideSaving: 500 } })
    const notRetained = bannerScenario({ policy: { acceptsOutsideFavoriteSystem: true, minimumOutsideSaving: 100 } })
    const bannerOf = (wrapper: VueWrapper) => textOf(wrapper, 'station-banner-reaction')
    const switchTestId = 'station-banner-reaction-switch'

    await click(onlyFavorite, switchTestId)
    await click(belowThreshold, switchTestId)
    await click(notRetained, switchTestId)

    expect({
      onlyFavorite: {
        station: bannerOf(onlyFavorite).includes(STATION_NAMES[REACTION_RECOMMENDED_ID]),
        saving: digitsOf(bannerOf(onlyFavorite)).includes('100'),
        reason: /n'accepte que le système préféré/.test(bannerOf(onlyFavorite)),
        role: attributeOf(onlyFavorite, 'station-banner-reaction', 'role'),
        action: textOf(onlyFavorite, switchTestId),
        emitted: onlyFavorite.emitted('update:policy'),
        otherTypeBanner: byTestId(onlyFavorite, 'station-banner-components').exists(),
      },
      belowThreshold: {
        station: bannerOf(belowThreshold).includes(STATION_NAMES[REACTION_RECOMMENDED_ID]),
        saving: digitsOf(bannerOf(belowThreshold)).includes('100'),
        reason: /sous le seuil du projet/.test(bannerOf(belowThreshold)),
        thresholdShown: digitsOf(bannerOf(belowThreshold)).includes('500'),
        action: textOf(belowThreshold, switchTestId),
        emitted: belowThreshold.emitted('update:policy'),
      },
      notRetained: {
        station: bannerOf(notRetained).includes(STATION_NAMES[REACTION_RECOMMENDED_ID]),
        saving: digitsOf(bannerOf(notRetained)).includes('100'),
        otherReason: /n'accepte que|seuil/.test(bannerOf(notRetained)),
        action: textOf(notRetained, switchTestId),
        emitted: notRetained.emitted('update:policy'),
      },
      rawKey: [onlyFavorite, belowThreshold, notRetained].map(wrapper => RAW_TRANSLATION_KEY.test(wrapper.text())),
    }).toEqual({
      onlyFavorite: {
        station: true,
        saving: true,
        reason: true,
        role: 'status',
        action: 'Autoriser pour ce projet',
        emitted: [[{ acceptsOutsideFavoriteSystem: true }]],
        otherTypeBanner: false,
      },
      belowThreshold: {
        station: true,
        saving: true,
        reason: true,
        thresholdShown: true,
        action: 'Revenir au système préféré',
        emitted: [[{ acceptsOutsideFavoriteSystem: false }]],
      },
      notRetained: {
        station: true,
        saving: true,
        otherReason: false,
        action: 'Revenir au système préféré',
        emitted: [[{ acceptsOutsideFavoriteSystem: false }]],
      },
      rawKey: [false, false, false],
    })
  })

  it('keeps the banner of a locked type, disables its action while a policy request runs and shows no banner once the cheapest station is picked', async () => {
    const locked = mountStations(
      projectWorkStations([
        reactionType({
          locked: true,
          lockReason: 'job_launched',
          recommendedStructureId: REACTION_RECOMMENDED_ID,
          rows: [
            stationRow(REACTION_RECOMMENDED_ID, { totalCost: 900, inFavoriteSystem: false }),
            stationRow(REACTION_ALTERNATIVE_ID, { totalCost: 1000, inFavoriteSystem: true }),
          ],
        }),
      ]),
      { reaction: REACTION_ALTERNATIVE_ID },
      { policyBusy: true },
    )
    const cheapestPicked = mountStations(projectWorkStations([reactionType({ rows: [stationRow(REACTION_RECOMMENDED_ID, { totalCost: 900 }), stationRow(REACTION_ALTERNATIVE_ID, { totalCost: 1000 })] })]), { reaction: REACTION_RECOMMENDED_ID })

    await click(locked, 'station-banner-reaction-switch')

    expect({
      lockedBannerShown: byTestId(locked, 'station-banner-reaction').exists(),
      switchDisabled: isDisabled(locked, 'station-banner-reaction-switch'),
      emitted: locked.emitted('update:policy'),
      cheapestPickedBanner: byTestId(cheapestPicked, 'station-banner-reaction').exists(),
    }).toEqual({ lockedBannerShown: true, switchDisabled: true, emitted: undefined, cheapestPickedBanner: false })
  })

  it('shows no notice when no structure is left out for its hull to specify', () => {
    const wrapper = mountStations(projectWorkStations(), {})

    expect({
      notice: byTestId(wrapper, HULL_NOTICE_TEST_ID).exists(),
      title: wrapper.text().includes(HULL_NOTICE_TITLE.fr),
    }).toEqual({ notice: false, title: false })
  })

  it('shows the notice even when the types of work have no row', () => {
    const wrapper = mountStations(projectWorkStations([], [LEGACY_REFINERY]), {})

    expect({
      notice: byTestId(wrapper, HULL_NOTICE_TEST_ID).exists(),
      name: textOf(wrapper, HULL_NOTICE_TEST_ID).includes(LEGACY_REFINERY.name),
      emptyMessage: wrapper.text().includes(fr.groupIndustry.setup.stations.empty),
    }).toEqual({ notice: true, name: true, emptyMessage: true })
  })
})
