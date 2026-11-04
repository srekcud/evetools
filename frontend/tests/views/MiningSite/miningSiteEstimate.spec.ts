import type { MiningSiteScan, MiningSiteScanType } from '@/stores/miningSite'
import {
  clampRescanMinutes,
  curveSeriesOf,
  etaEvolutionOf,
  etaTierOf,
  isIrregularRate,
  isRescanDue,
  mostProfitableTypeOf,
  readRescanMinutes,
  remainingVolumeRingOf,
} from '@/views/MiningSite/miningSiteEstimate'
import { KERNITE, PLAGIOCLASE, SCORDITE, VELDSPAR, at, depletedType, presentType, scanOf, siteOf, veldsparAndScorditeScans } from './miningSiteFixtures'

function lastScanEndingAt(trendEta: string | undefined): MiningSiteScan {
  return scanOf(3, at('20:20:00'), [presentType(VELDSPAR, 18_000, { trendEta, volumeM3: 1_800 })], { trendEta })
}

function rounded(series: unknown): unknown {
  return JSON.parse(JSON.stringify(series, (_key, value: unknown) => (typeof value === 'number' ? Math.round(value * 100) / 100 : value)))
}

describe('etaTierOf', () => {
  it('colours the remaining time to the trend ETA, and says unknown, depleted or nothing for a closed site', () => {
    const now = new Date(at('20:30:00'))
    const everythingDepleted = scanOf(4, at('20:20:00'), [depletedType(VELDSPAR, at('20:10:00'), at('20:20:00')), depletedType(SCORDITE, at('20:10:00'), at('20:20:00'))])

    expect({
      in20Minutes: etaTierOf('active', lastScanEndingAt(at('20:50:00')), now),
      in15Minutes: etaTierOf('active', lastScanEndingAt(at('20:45:00')), now),
      in14Minutes59: etaTierOf('active', lastScanEndingAt(at('20:44:59')), now),
      in10Minutes: etaTierOf('active', lastScanEndingAt(at('20:40:00')), now),
      in5Minutes: etaTierOf('active', lastScanEndingAt(at('20:35:00')), now),
      in4Minutes59: etaTierOf('active', lastScanEndingAt(at('20:34:59')), now),
      in4Minutes: etaTierOf('active', lastScanEndingAt(at('20:34:00')), now),
      now: etaTierOf('active', lastScanEndingAt(at('20:30:00')), now),
      past: etaTierOf('active', lastScanEndingAt(at('20:20:00')), now),
      noEta: etaTierOf('active', lastScanEndingAt(undefined), now),
      everythingDepleted: etaTierOf('active', everythingDepleted, now),
      closedWithRemainingTypes: etaTierOf('closed', lastScanEndingAt(at('20:50:00')), now),
    }).toEqual({
      in20Minutes: 'normal',
      in15Minutes: 'normal',
      in14Minutes59: 'orange',
      in10Minutes: 'orange',
      in5Minutes: 'orange',
      in4Minutes59: 'red',
      in4Minutes: 'red',
      now: 'reached',
      past: 'reached',
      noEta: 'unknown',
      everythingDepleted: 'depleted',
      closedWithRemainingTypes: null,
    })
  })
})

describe('remainingVolumeRingOf', () => {
  it('divides the volume of the last scan by the volume at the first appearance of each type that is being mined', () => {
    const veldsparMinedScorditeNot = [
      scanOf(1, at('20:00:00'), [presentType(VELDSPAR, 30_000, { volumeM3: 3_000 }), presentType(SCORDITE, 5_000, { volumeM3: 750 })]),
      scanOf(2, at('20:10:00'), [presentType(VELDSPAR, 18_000, { volumeM3: 1_800 }), presentType(SCORDITE, 5_000, { volumeM3: 750, etaUnknownReason: 'not_depleting' })]),
    ]
    const plagioclaseAppearsAtTheSecondScan = [
      scanOf(1, at('20:00:00'), [presentType(VELDSPAR, 30_000, { volumeM3: 3_000 })]),
      scanOf(2, at('20:10:00'), [presentType(VELDSPAR, 24_000, { volumeM3: 2_400 }), presentType(PLAGIOCLASE, 8_000, { volumeM3: 2_800 })]),
      scanOf(3, at('20:20:00'), [presentType(VELDSPAR, 18_000, { volumeM3: 1_800 }), presentType(PLAGIOCLASE, 4_000, { volumeM3: 1_400 })]),
    ]
    const everythingDepleted = [
      scanOf(1, at('20:00:00'), [presentType(VELDSPAR, 30_000, { volumeM3: 3_000 })]),
      scanOf(2, at('20:10:00'), [depletedType(VELDSPAR, at('20:00:00'), at('20:10:00'))]),
    ]
    const aVolumeIsUnknown = [
      scanOf(1, at('20:00:00'), [presentType(VELDSPAR, 30_000, { volumeM3: 3_000 })]),
      scanOf(2, at('20:10:00'), [presentType(VELDSPAR, 18_000)]),
    ]

    expect({
      scorditeNotMinedIsIgnored: remainingVolumeRingOf(veldsparMinedScorditeNot),
      laterTypeCountsFromItsFirstAppearance: remainingVolumeRingOf(plagioclaseAppearsAtTheSecondScan),
      everythingDepleted: remainingVolumeRingOf(everythingDepleted),
      unknownVolume: remainingVolumeRingOf(aVolumeIsUnknown),
    }).toEqual({
      scorditeNotMinedIsIgnored: expect.closeTo(60, 6),
      laterTypeCountsFromItsFirstAppearance: expect.closeTo(55.1724, 3),
      everythingDepleted: 0,
      unknownVolume: null,
    })
  })
})

describe('mostProfitableTypeOf', () => {
  it('picks the type with the highest value per m3, the first of the table on a tie', () => {
    const veldspar = presentType(VELDSPAR, 18_000, { volumeM3: 1_800, unitPrice: 15 })
    const scordite = presentType(SCORDITE, 5_000, { volumeM3: 750, unitPrice: 25 })
    const plagioclaseAtTheSameValuePerM3 = presentType(PLAGIOCLASE, 1_000, { volumeM3: 350, unitPrice: 52.5 })

    expect({
      scorditeAt166PerM3: mostProfitableTypeOf(scanOf(1, at('20:00:00'), [veldspar, scordite]))?.typeId,
      tieKeepsVeldsparFirst: mostProfitableTypeOf(scanOf(1, at('20:00:00'), [veldspar, plagioclaseAtTheSameValuePerM3]))?.typeId,
      tieKeepsPlagioclaseFirst: mostProfitableTypeOf(scanOf(1, at('20:00:00'), [plagioclaseAtTheSameValuePerM3, veldspar]))?.typeId,
    }).toEqual({
      scorditeAt166PerM3: SCORDITE,
      tieKeepsVeldsparFirst: VELDSPAR,
      tieKeepsPlagioclaseFirst: PLAGIOCLASE,
    })
  })

  it('ignores the types without price, without volume or depleted, and gives none when nothing is eligible', () => {
    const veldsparWithoutPrice = presentType(VELDSPAR, 18_000, { volumeM3: 1_800 })
    const scordite = presentType(SCORDITE, 5_000, { volumeM3: 750, unitPrice: 25 })
    const plagioclaseWithoutVolume = presentType(PLAGIOCLASE, 1_000, { unitPrice: 500 })
    const depletedKernite = depletedType(KERNITE, at('20:00:00'), at('20:10:00'), { unitPrice: 900 })

    expect({
      scorditeIsTheOnlyEligible: mostProfitableTypeOf(scanOf(1, at('20:00:00'), [veldsparWithoutPrice, scordite, plagioclaseWithoutVolume, depletedKernite]))?.typeId,
      nothingEligible: mostProfitableTypeOf(scanOf(1, at('20:00:00'), [veldsparWithoutPrice, plagioclaseWithoutVolume, depletedKernite])),
    }).toEqual({
      scorditeIsTheOnlyEligible: SCORDITE,
      nothingEligible: null,
    })
  })
})

describe('isIrregularRate', () => {
  it('flags a deviation above 10 percent only', () => {
    const withDeviation = (maxDeviationPercent: number | null | undefined): MiningSiteScanType => presentType(VELDSPAR, 18_000, { maxDeviationPercent })

    expect({
      at12_22: isIrregularRate(withDeviation(12.22)),
      at2_22: isIrregularRate(withDeviation(2.22)),
      at10: isIrregularRate(withDeviation(10)),
      at10_01: isIrregularRate(withDeviation(10.01)),
      missing: isIrregularRate(withDeviation(undefined)),
      nullValue: isIrregularRate(withDeviation(null)),
    }).toEqual({ at12_22: true, at2_22: false, at10: false, at10_01: true, missing: false, nullValue: false })
  })
})

describe('re-scan reminder', () => {
  it('is due when the last scan of an active site is older than the delay, which is kept between 3 and 30 minutes', () => {
    const active = siteOf({ lastScanAt: at('20:20:00') })
    const storedDelay = (value: string | null) => ({ getItem: vi.fn((key: string) => (key === 'miningSite.rescanMinutes' ? value : null)) })

    expect({
      elevenMinutesWithDelay10: isRescanDue(active, new Date(at('20:31:00')), 10),
      nineMinutesWithDelay10: isRescanDue(active, new Date(at('20:29:00')), 10),
      nineMinutesWithDelay5: isRescanDue(active, new Date(at('20:29:00')), 5),
      closedSite: isRescanDue(siteOf({ status: 'closed', lastScanAt: at('20:20:00') }), new Date(at('20:31:00')), 10),
      siteWithoutScan: isRescanDue(siteOf(), new Date(at('20:31:00')), 10),
      clamped: [2, 3, 17, 30, 45].map(clampRescanMinutes),
      stored: [storedDelay('5'), storedDelay(null), storedDelay('abc'), storedDelay('1'), storedDelay('99')].map(readRescanMinutes),
    }).toEqual({
      elevenMinutesWithDelay10: true,
      nineMinutesWithDelay10: false,
      nineMinutesWithDelay5: true,
      closedSite: false,
      siteWithoutScan: false,
      clamped: [3, 3, 17, 30, 30],
      stored: [5, 10, 10, 3, 30],
    })
  })
})

describe('etaEvolutionOf', () => {
  it('lists the ETA announced at each scan, the most recent first, unknown as null', () => {
    expect(etaEvolutionOf(veldsparAndScorditeScans())).toEqual([
      { number: 3, scannedAt: at('20:20:00'), trendEta: at('20:50:33'), recentEta: at('20:45:43') },
      { number: 2, scannedAt: at('20:10:00'), trendEta: at('21:00:00'), recentEta: at('21:00:00') },
      { number: 1, scannedAt: at('20:00:00'), trendEta: null, recentEta: null },
    ])
  })
})

describe('curveSeriesOf', () => {
  it('draws one point per scan where the type is present, the trend line down to 0, the recent segment and the depletion marker', () => {
    const scans = [
      scanOf(1, at('20:00:00'), [presentType(VELDSPAR, 30_000), presentType(SCORDITE, 5_000), presentType(KERNITE, 2_000)]),
      scanOf(2, at('20:10:00'), [presentType(VELDSPAR, 25_000), presentType(SCORDITE, 5_000), presentType(KERNITE, 1_500)]),
      scanOf(3, at('20:20:00'), [
        presentType(VELDSPAR, 18_000, { recentRate: 700, recentEta: at('20:45:43'), trendRate: 600, trendEta: at('20:50:33') }),
        presentType(SCORDITE, 5_000, { etaUnknownReason: 'not_depleting' }),
        depletedType(KERNITE, at('20:10:00'), at('20:20:00')),
      ]),
    ]

    expect(rounded(curveSeriesOf(scans, 'units'))).toEqual({
      unit: 'units',
      m3Available: false,
      types: [
        {
          typeId: VELDSPAR,
          notDepleting: false,
          points: [{ minutes: 0, value: 30_000 }, { minutes: 10, value: 25_000 }, { minutes: 20, value: 18_000 }],
          trendLine: [{ minutes: 0, value: 30_330 }, { minutes: 50.55, value: 0 }],
          recentSegment: [{ minutes: 20, value: 18_000 }, { minutes: 45.72, value: 0 }],
          depletionMarker: null,
        },
        {
          typeId: SCORDITE,
          notDepleting: true,
          points: [{ minutes: 0, value: 5_000 }, { minutes: 10, value: 5_000 }, { minutes: 20, value: 5_000 }],
          trendLine: null,
          recentSegment: null,
          depletionMarker: null,
        },
        {
          typeId: KERNITE,
          notDepleting: false,
          points: [{ minutes: 0, value: 2_000 }, { minutes: 10, value: 1_500 }],
          trendLine: null,
          recentSegment: null,
          depletionMarker: { minutes: 20, value: 0 },
        },
      ],
    })
  })

  it('stops the lines at 300 minutes after the first scan, and offers cubic metres only when every shown volume is known', () => {
    const farEta = '2026-11-06T06:00:00+00:00'
    const slowScans = [
      scanOf(1, at('20:00:00'), [presentType(VELDSPAR, 60_000, { volumeM3: 6_000 })]),
      scanOf(2, at('20:10:00'), [presentType(VELDSPAR, 59_000, { volumeM3: 5_900, recentRate: 100, recentEta: farEta, trendRate: 100, trendEta: farEta })]),
    ]
    const volumeMissingAtTheSecondScan = [
      slowScans[0],
      scanOf(2, at('20:10:00'), [presentType(VELDSPAR, 59_000)]),
    ]

    const unitsSeries = rounded(curveSeriesOf(slowScans, 'units')) as { m3Available: boolean; types: { trendLine: unknown; recentSegment: unknown }[] }
    const cubicMetres = rounded(curveSeriesOf(slowScans, 'm3')) as { unit: string; types: { points: unknown; trendLine: unknown }[] }

    expect({
      clippedUnits: [unitsSeries.types[0]?.trendLine, unitsSeries.types[0]?.recentSegment],
      unitsAvailability: unitsSeries.m3Available,
      cubicMetres: [cubicMetres.unit, cubicMetres.types[0]?.points, cubicMetres.types[0]?.trendLine],
      volumeMissing: curveSeriesOf(volumeMissingAtTheSecondScan, 'units').m3Available,
    }).toEqual({
      clippedUnits: [
        [{ minutes: 0, value: 60_000 }, { minutes: 300, value: 30_000 }],
        [{ minutes: 10, value: 59_000 }, { minutes: 300, value: 30_000 }],
      ],
      unitsAvailability: true,
      cubicMetres: ['m3', [{ minutes: 0, value: 6_000 }, { minutes: 10, value: 5_900 }], [{ minutes: 0, value: 6_000 }, { minutes: 300, value: 3_000 }]],
      volumeMissing: false,
    })
  })
})
