import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import MiningOperationSites from '@/components/mining-operation/MiningOperationSites.vue'
import type { MiningSite } from '@/stores/miningSite'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { compression } from '../../views/MiningOperation/miningOperationLedgerFixtures'
import { OPERATION_ID, OTHER_SITE_ID, SITE_ID, at, siteOf } from '../../views/MiningSite/miningSiteFixtures'

const ORCA_TYPE_ID = 28_606

function siteWith(id: string, label: string, gaps: { noHull: ('moon' | 'ice' | 'gas')[]; notMounted: ('moon' | 'ice' | 'gas')[] }): MiningSite {
  return siteOf({
    id,
    label,
    operationId: OPERATION_ID,
    lastScanAt: at('20:20:00'),
    compression: compression({ boostShipTypeId: ORCA_TYPE_ID, boostShipName: 'Orca', gaps }),
  })
}

function mountSites(sites: MiningSite[]) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(MiningOperationSites, {
    props: { operationId: OPERATION_ID, sites, failure: null, canCreate: false, now: Date.parse(at('20:30:00')) },
    global: { plugins: [i18n], stubs: { MiningSiteCreateModal: true, RouterLink: { template: '<a><slot /></a>' } } },
  })
  return { wrapper, t: i18n.global.t }
}

enableAutoUnmount(afterEach)

describe('MiningOperationSites compression', () => {
  it('warns once, in amber, about the kinds that the sites need and the boost ship cannot compress or has not mounted, with the unchanged shares mention', () => {
    const { wrapper, t } = mountSites([
      siteWith(SITE_ID, 'Belt Aldranette', { noHull: ['moon'], notMounted: ['ice'] }),
      siteWith(OTHER_SITE_ID, 'Belt Jaspet', { noHull: ['moon'], notMounted: ['gas'] }),
    ])

    const alerts = wrapper.findAll('[role="alert"]').map(alert => alert.text())
    expect({ alerts, shares: wrapper.text().includes(t('miningOperation.boostFit.sharesUnchanged')) }).toEqual({
      alerts: [
        t('miningOperation.boostFit.noHullBanner', { ship: 'Orca', kinds: t('miningOperation.boostFit.kind.moon').toLocaleLowerCase('en') }),
        t('miningOperation.boostFit.notMountedBanner', {
          kinds: [t('miningOperation.boostFit.kind.ice'), t('miningOperation.boostFit.kind.gas')].map(kind => kind.toLocaleLowerCase('en')).join(', '),
        }),
      ],
      shares: true,
    })
  })

  it('shows no banner when no site has a gap, or when the sites carry no compression', () => {
    const withoutGap = mountSites([siteWith(SITE_ID, 'Belt Aldranette', { noHull: [], notMounted: [] })])
    const withoutGapResult = [withoutGap.wrapper.findAll('[role="alert"]').length, withoutGap.wrapper.text().includes(withoutGap.t('miningOperation.boostFit.sharesUnchanged'))]
    withoutGap.wrapper.unmount()
    const withoutCompression = mountSites([siteOf({ id: SITE_ID, operationId: OPERATION_ID })])

    expect([withoutGapResult, [withoutCompression.wrapper.findAll('[role="alert"]').length, withoutCompression.wrapper.text().includes(withoutCompression.t('miningOperation.boostFit.sharesUnchanged'))]]).toEqual([[0, false], [0, false]])
  })
})
