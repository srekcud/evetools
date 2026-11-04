import { enableAutoUnmount, mount } from '@vue/test-utils'
import MiningSiteScanFeed from '@/components/mining-site/MiningSiteScanFeed.vue'
import type { MiningSiteScan } from '@/stores/miningSite'
import { SCORDITE, VELDSPAR, alphaBravoCharlieScans, at, hasAmberText, i18nFor, presentType, scanOf } from '../../views/MiningSite/miningSiteFixtures'

const LONG_AUTHOR_NAME = 'Cyra Lune of the Exceedingly Long Named Boosting Wing Alliance of the Outer Ring'

function mountFeed(scans: MiningSiteScan[]) {
  const i18n = i18nFor('fr')
  const wrapper = mount(MiningSiteScanFeed, { props: { scans }, global: { plugins: [i18n] } })
  return { wrapper, t: i18n.global.t }
}

function rowsOf(wrapper: ReturnType<typeof mountFeed>['wrapper']) {
  return wrapper.findAll('[data-testid="scan-feed-row"]')
}

function normalized(text: string): string {
  return text.replace(/\s+/g, ' ').trim()
}

enableAutoUnmount(afterEach)

describe('MiningSiteScanFeed', () => {
  it('lists the scans from the newest to the oldest with number, EVE time, author, type count and trend ETA', () => {
    const { wrapper, t } = mountFeed(alphaBravoCharlieScans())

    const rows = rowsOf(wrapper)
    expect({
      numbers: rows.map(row => row.attributes('data-scan-number')),
      newest: normalized(rows[0]?.text() ?? ''),
      middle: normalized(rows[1]?.text() ?? ''),
      oldest: normalized(rows[2]?.text() ?? ''),
    }).toEqual({
      numbers: ['3', '2', '1'],
      newest: expect.stringMatching(/^#3.*20:20.*Charlie.*1 type.*20:50$/),
      middle: expect.stringMatching(new RegExp(`^#2.*20:10.*Bravo.*1 type.*${t('miningSite.eta.unknown')}$`)),
      oldest: expect.stringMatching(/^#1.*20:00.*Alpha.*1 type/),
    })
  })

  it('keeps the five most recent scans whatever the order they are given in', () => {
    const scans = [3, 1, 6, 2, 5, 4].map(number => scanOf(number, at(`20:0${number}:00`), [presentType(SCORDITE, number * 1_000)], { authorName: `Pilot ${number}` }))

    const { wrapper } = mountFeed(scans)

    expect(rowsOf(wrapper).map(row => row.attributes('data-scan-number'))).toEqual(['6', '5', '4', '3', '2'])
  })

  it('names an unknown author in amber instead of a default name and truncates a very long name under its full title', () => {
    const scans = [
      scanOf(1, at('20:00:00'), [presentType(VELDSPAR, 30_000)], { authorName: 'Alpha' }),
      scanOf(2, at('20:10:00'), [presentType(VELDSPAR, 24_000)], { authorName: null }),
      scanOf(3, at('20:20:00'), [presentType(VELDSPAR, 18_000)], { authorName: LONG_AUTHOR_NAME }),
    ]

    const { wrapper } = mountFeed(scans)

    const [longRow, unknownRow, knownRow] = rowsOf(wrapper)
    const longName = longRow?.find(`[title="${LONG_AUTHOR_NAME}"]`)
    expect({
      unknownInAmber: unknownRow !== undefined && hasAmberText(unknownRow, 'auteur inconnu'),
      knownAuthorInAmber: knownRow !== undefined && hasAmberText(knownRow, 'Alpha'),
      unknownRowText: normalized(unknownRow?.text() ?? ''),
      longNameTruncated: longName?.exists() === true && longName.classes().includes('truncate'),
    }).toEqual({
      unknownInAmber: true,
      knownAuthorInAmber: false,
      unknownRowText: expect.stringContaining('auteur inconnu'),
      longNameTruncated: true,
    })
  })
})
