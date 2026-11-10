import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import MiningCompanionPanel from '@/components/mining-site/MiningCompanionPanel.vue'
import type { MiningCompression } from '@/stores/miningOperation'
import type { MiningSite } from '@/stores/miningSite'
import { compression } from '../../views/MiningOperation/miningOperationLedgerFixtures'
import { SCORDITE, VELDSPAR, at, i18nFor, siteAfterThreeScans, veldsparAndScorditeScans } from '../../views/MiningSite/miningSiteFixtures'

const PORPOISE_TYPE_ID = 42_244
const RESCAN_MINUTES = 10

function porpoiseCompression(): MiningCompression {
  return compression({
    boostShipTypeId: PORPOISE_TYPE_ID,
    boostShipName: 'Porpoise',
    types: [
      { typeId: VELDSPAR, compressible: true, status: 'on_site' },
      { typeId: SCORDITE, compressible: false, status: 'no_hull_compressor' },
    ],
    gaps: { noHull: ['moon'], notMounted: ['ice'] },
  })
}

function mountPanel(size: 'bar' | 'compact' | 'detail', site: MiningSite) {
  const i18n = i18nFor('en')
  const wrapper = mount(MiningCompanionPanel, {
    props: {
      site,
      scans: veldsparAndScorditeScans(),
      size,
      now: new Date(at('20:25:00')),
      rescanMinutes: RESCAN_MINUTES,
      typeNames: { [VELDSPAR]: 'Veldspar', [SCORDITE]: 'Scordite' },
      operation: null,
      notice: null,
    },
    global: { plugins: [i18n] },
  })
  return { wrapper, t: i18n.global.t }
}

function compressionAlerts(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[role="alert"]').map(alert => alert.text())
}

function compressionBadgeOf(wrapper: VueWrapper, typeId: number): string | null {
  const badge = wrapper.find(`[data-testid="companion-type-row-${typeId}"] [data-testid="companion-type-compression"]`)
  return badge.exists() ? badge.text() : null
}

function siteWith(value: MiningCompression | null): MiningSite {
  return siteAfterThreeScans({ compression: value })
}

enableAutoUnmount(afterEach)

describe('MiningCompanionPanel compression', () => {
  it('badges each type row in the detail size and warns about the kinds the hull cannot compress and the kinds not mounted', () => {
    const { wrapper, t } = mountPanel('detail', siteWith(porpoiseCompression()))

    expect({
      badges: [VELDSPAR, SCORDITE].map(typeId => compressionBadgeOf(wrapper, typeId)),
      alerts: compressionAlerts(wrapper),
      shares: wrapper.text().includes(t('miningOperation.boostFit.sharesUnchanged')),
    }).toEqual({
      badges: [t('miningOperation.boostFit.badge.onSite'), t('miningOperation.boostFit.badge.none')],
      alerts: [
        t('miningOperation.boostFit.noHullBanner', { ship: 'Porpoise', kinds: t('miningOperation.boostFit.kind.moon').toLocaleLowerCase('en') }),
        t('miningOperation.boostFit.notMountedBanner', { kinds: t('miningOperation.boostFit.kind.ice').toLocaleLowerCase('en') }),
      ],
      shares: true,
    })
  })

  it('warns in the compact size, which has no type table and so no badge', () => {
    const { wrapper, t } = mountPanel('compact', siteWith(porpoiseCompression()))

    expect({
      alerts: compressionAlerts(wrapper),
      shares: wrapper.text().includes(t('miningOperation.boostFit.sharesUnchanged')),
      badge: compressionBadgeOf(wrapper, VELDSPAR),
    }).toEqual({
      alerts: [
        t('miningOperation.boostFit.noHullBanner', { ship: 'Porpoise', kinds: t('miningOperation.boostFit.kind.moon').toLocaleLowerCase('en') }),
        t('miningOperation.boostFit.notMountedBanner', { kinds: t('miningOperation.boostFit.kind.ice').toLocaleLowerCase('en') }),
      ],
      shares: true,
      badge: null,
    })
  })

  it('shows neither banner nor badge in the bar size', () => {
    const { wrapper, t } = mountPanel('bar', siteWith(porpoiseCompression()))

    expect({ alerts: compressionAlerts(wrapper), shares: wrapper.text().includes(t('miningOperation.boostFit.sharesUnchanged')) }).toEqual({ alerts: [], shares: false })
  })

  it('shows neither banner nor badge without a compression (private site) or without a boost ship', () => {
    const privateSite = mountPanel('detail', siteWith(null))
    const privateResult = { alerts: compressionAlerts(privateSite.wrapper), badge: compressionBadgeOf(privateSite.wrapper, VELDSPAR), shares: privateSite.wrapper.text().includes(privateSite.t('miningOperation.boostFit.sharesUnchanged')) }
    privateSite.wrapper.unmount()
    const withoutShip = mountPanel('detail', siteWith(compression({ types: [{ typeId: VELDSPAR, compressible: null, status: 'no_boost_ship' }] })))

    expect({
      privateSite: privateResult,
      withoutShip: { alerts: compressionAlerts(withoutShip.wrapper), badge: compressionBadgeOf(withoutShip.wrapper, VELDSPAR), shares: withoutShip.wrapper.text().includes(withoutShip.t('miningOperation.boostFit.sharesUnchanged')) },
    }).toEqual({
      privateSite: { alerts: [], badge: null, shares: false },
      withoutShip: { alerts: [], badge: null, shares: false },
    })
  })
})
