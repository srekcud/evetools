import { enableAutoUnmount, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createI18n } from 'vue-i18n'
import MiningHaulerPlan from '@/components/mining-operation/MiningHaulerPlan.vue'
import type { MiningHaulerPlan as HaulerPlan, MiningHaulerPlanUnknownReason } from '@/stores/miningOperation'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { HAULER_TYPE_NAMES, haulerAttribute, haulerElement, haulerText, knownHaulerPlan, unknownHaulerPlan } from './haulerPlanFixtures'

const UNKNOWN_REASONS: MiningHaulerPlanUnknownReason[] = [
  'operation_not_running',
  'no_boost_ship',
  'no_fleet_storage_recipient',
  'no_active_site',
  'site_rate_unknown',
  'ledger_unknown',
  'nothing_delivered',
  'capacity_missing_from_sde',
  'volume_missing',
  'compression_unknown',
]

function mountPlan(plan: HaulerPlan, canDeclareEmptied = false) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(MiningHaulerPlan, {
    props: { plan, canDeclareEmptied, typeNames: HAULER_TYPE_NAMES },
    global: { plugins: [i18n] },
  })
  return { wrapper, t: i18n.global.t, te: i18n.global.te }
}

enableAutoUnmount(afterEach)

describe('MiningHaulerPlan', () => {
  it('shows the fill bar at 50 % in amber with the time before it is full, the trips before the site ends, the raw ore, the calculation and the estimate sentence', () => {
    const { wrapper, t } = mountPlan(knownHaulerPlan())

    expect({
      width: haulerAttribute(wrapper, 'hauler-fill', 'style'),
      tone: haulerAttribute(wrapper, 'hauler-fill', 'data-tone'),
      fillText: haulerText(wrapper, 'hauler-fill-text'),
      trips: haulerText(wrapper, 'hauler-trips'),
      calculation: haulerText(wrapper, 'hauler-calculation'),
      estimate: haulerText(wrapper, 'hauler-estimate'),
    }).toEqual({
      width: expect.stringContaining('width: 50%'),
      tone: 'amber',
      fillText: expect.stringMatching(/50 %.*4 min/),
      trips: expect.stringMatching(/3.*21:10.*320,000.*Zeolites/),
      calculation: expect.stringMatching(/5,000.*50 %/),
      estimate: t('miningOperation.haulerPlan.estimate'),
    })
  })

  it('says the hangar is full and has to be emptied, with the bar at 100 % in red', () => {
    const { wrapper, t } = mountPlan(knownHaulerPlan({ fillM3: 40_000, fillRatio: 1, fullInMinutes: 0, isFull: true }))

    expect({
      width: haulerAttribute(wrapper, 'hauler-fill', 'style'),
      tone: haulerAttribute(wrapper, 'hauler-fill', 'data-tone'),
      fillText: haulerText(wrapper, 'hauler-fill-text'),
    }).toEqual({
      width: expect.stringContaining('width: 100%'),
      tone: 'red',
      fillText: t('miningOperation.haulerPlan.full'),
    })
  })

  it('keeps the bar in cyan and says the trips are unknown, never zero, when the site end time is unknown', () => {
    const { wrapper, t } = mountPlan(knownHaulerPlan({ fillM3: 8_000, fillRatio: 0.2, fullInMinutes: 12, toMoveM3: null, trips: null, siteEtaAt: null }))

    expect({
      width: haulerAttribute(wrapper, 'hauler-fill', 'style'),
      tone: haulerAttribute(wrapper, 'hauler-fill', 'data-tone'),
      trips: haulerText(wrapper, 'hauler-trips'),
    }).toEqual({
      width: expect.stringContaining('width: 20%'),
      tone: 'cyan',
      trips: t('miningOperation.haulerPlan.tripsUnknown'),
    })
  })

  it('names the hauler and translates the reason, without any bar, for an unknown plan, in English and French for every reason', () => {
    const { wrapper, t, te } = mountPlan(unknownHaulerPlan('no_active_site'))

    expect({
      title: haulerText(wrapper, 'hauler-unknown'),
      reason: haulerText(wrapper, 'hauler-unknown-reason'),
      bar: haulerElement(wrapper, 'hauler-fill') !== null,
      reasonsTranslated: UNKNOWN_REASONS.every(reason => te(`miningOperation.haulerPlan.reason.${reason}`, 'en') && te(`miningOperation.haulerPlan.reason.${reason}`, 'fr')),
    }).toEqual({
      title: expect.stringContaining(t('miningOperation.haulerPlan.unknown', { hauler: 'Brann Holt' })),
      reason: t('miningOperation.haulerPlan.reason.no_active_site'),
      bar: false,
      reasonsTranslated: true,
    })
  })

  it('offers the emptied button only when the viewer can declare it, and emits the declaration on click', async () => {
    const forbidden = mountPlan(knownHaulerPlan(), false)
    const forbiddenResult = haulerElement(forbidden.wrapper, 'hauler-emptied-button') !== null
    forbidden.wrapper.unmount()
    const allowed = mountPlan(knownHaulerPlan(), true)

    haulerElement(allowed.wrapper, 'hauler-emptied-button')?.click()
    await nextTick()

    expect({ forbidden: forbiddenResult, label: haulerText(allowed.wrapper, 'hauler-emptied-button'), emitted: allowed.wrapper.emitted('declare-emptied')?.length }).toEqual({
      forbidden: false,
      label: allowed.t('miningOperation.haulerPlan.emptied'),
      emitted: 1,
    })
  })
})
