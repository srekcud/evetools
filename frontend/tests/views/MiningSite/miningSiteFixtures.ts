import type { DOMWrapper, VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import type { OreValueRow, OreValueTableResponse } from '@/composables/useOreValueTable'
import type { MiningSite, MiningSiteScan, MiningSiteScanType } from '@/stores/miningSite'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import type { TestLocale } from '../../localeTexts'
import { OPERATION_O } from '../MiningOperation/miningOperationLedgerFixtures'

export const OPERATION_ID = OPERATION_O
export const OTHER_SITE_ID = '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e51'
export const SITE_ID = '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e4f'
export const SITES_PATH = '/mining-sites'
export const SITE_PATH = `${SITES_PATH}/${SITE_ID}`
export const SCANS_PATH = `${SITE_PATH}/scans`
export const CLOSE_PATH = `${SITE_PATH}/close`
export const ORE_VALUES_PATH = '/mining/ore-values'
export const SOLAR_SYSTEMS_PATH = '/sde/solar-systems'

export const ALDRANETTE = 30_003_504
export const JASPET = 30_003_505
export const VELDSPAR = 1230
export const SCORDITE = 1228
export const PLAGIOCLASE = 18
export const KERNITE = 20

export const LONG_ORE_NAME = 'Scordite Concentrée Extra-Dense de la Ceinture Extérieure des Anomalies Lointaines'
export const LONG_SITE_LABEL = 'Anomalie Spodumain du dimanche soir dans la ceinture la plus lointaine du secteur Ancbeu, Sinq Laiso'

export function found(parent: VueWrapper | DOMWrapper<Element>, selector: string): DOMWrapper<Element> {
  const element = parent.find(selector)
  expect(element.exists(), `element ${selector}`).toBe(true)
  return element
}

export function at(clock: string): string {
  return `2026-11-05T${clock}+00:00`
}

export function i18nFor(locale: TestLocale = 'en') {
  return createI18n({ legacy: false, locale, messages: { en, fr } })
}

function oreValueRow(typeId: number, typeName: string, volume: number): OreValueRow {
  return {
    typeId,
    typeName,
    kind: 'ore',
    groupName: typeName,
    volume,
    rawIskPerM3: null,
    compressedIskPerM3: null,
    reprocessedIskPerM3: null,
    bestOption: null,
    bestIskPerM3: null,
    gapToBestPercent: null,
    reprocessable: true,
    minerals: [],
  }
}

export function oreValues(scorditeName = 'Scordite'): OreValueTableResponse {
  return {
    market: 'jita',
    side: 'buy',
    structureId: null,
    reprocessYieldKnown: false,
    rows: [
      oreValueRow(VELDSPAR, 'Veldspar', 0.1),
      oreValueRow(SCORDITE, scorditeName, 0.15),
      oreValueRow(KERNITE, 'Kernite', 1.2),
    ],
  }
}

export function presentType(typeId: number, units: number, overrides: Partial<MiningSiteScanType> = {}): MiningSiteScanType {
  return { typeId, status: 'present', units, ...overrides }
}

export function depletedType(typeId: number, from: string, to: string, overrides: Partial<MiningSiteScanType> = {}): MiningSiteScanType {
  return { typeId, status: 'depleted', units: 0, volumeM3: 0, remainingValue: 0, depletedBetween: { from, to }, ...overrides }
}

export function scanOf(number: number, scannedAt: string, types: MiningSiteScanType[], overrides: Partial<MiningSiteScan> = {}): MiningSiteScan {
  return {
    id: `scan-${number}`,
    number,
    typeCount: types.length,
    scannedAt,
    totalUnits: types.reduce((total, type) => total + type.units, 0),
    missingPriceTypeIds: [],
    types,
    ...overrides,
  }
}

export function siteOf(overrides: Partial<MiningSite> = {}): MiningSite {
  return {
    id: SITE_ID,
    label: 'Belt Aldranette',
    solarSystemId: ALDRANETTE,
    solarSystemName: 'Aldranette',
    status: 'active',
    createdAt: at('19:55:00'),
    missingPriceTypeIds: [],
    ...overrides,
  }
}

export function hasAmberText(parent: DOMWrapper<Element>, text: string): boolean {
  return parent.findAll('*').some(element => element.text() === text && element.classes().some(name => name.includes('amber')))
}

export function alphaBravoCharlieScans(): MiningSiteScan[] {
  return [
    scanOf(1, at('20:00:00'), [presentType(VELDSPAR, 30_000, { volumeM3: 3_000 })], { authorName: 'Alpha' }),
    scanOf(2, at('20:10:00'), [presentType(VELDSPAR, 24_000, { volumeM3: 2_400 })], { authorName: 'Bravo' }),
    scanOf(3, at('20:20:00'), [presentType(VELDSPAR, 18_000, { volumeM3: 1_800, trendEta: at('20:50:33') })], { authorName: 'Charlie', trendEta: at('20:50:33') }),
  ]
}

export function siteAfterCharlieScan(overrides: Partial<MiningSite> = {}): MiningSite {
  return siteOf({
    operationId: OPERATION_ID,
    firstScanAt: at('20:00:00'),
    lastScanAt: at('20:20:00'),
    observedSeconds: 1_200,
    trendEta: at('20:50:33'),
    lastScanNumber: 3,
    lastScanAuthorName: 'Charlie',
    ...overrides,
  })
}

export function veldsparAndScorditeScans(): MiningSiteScan[] {
  return [
    scanOf(1, at('20:00:00'), [
      presentType(VELDSPAR, 30_000, { volumeM3: 3_000, unitPrice: 15, remainingValue: 450_000, etaUnknownReason: 'single_scan' }),
      presentType(SCORDITE, 5_000, { volumeM3: 750, unitPrice: 25, remainingValue: 125_000, etaUnknownReason: 'single_scan' }),
    ], { authorName: 'Aria Solberg', totalVolumeM3: 3_750, remainingValue: 575_000 }),
    scanOf(2, at('20:10:00'), [
      presentType(VELDSPAR, 25_000, { volumeM3: 2_500, unitPrice: 15, remainingValue: 375_000, recentRate: 500, recentEta: at('21:00:00'), trendRate: 500, trendEta: at('21:00:00') }),
      presentType(SCORDITE, 5_000, { volumeM3: 750, unitPrice: 25, remainingValue: 125_000, etaUnknownReason: 'not_depleting' }),
    ], { authorName: 'Brann Holt', totalVolumeM3: 3_250, remainingValue: 500_000, recentEta: at('21:00:00'), trendEta: at('21:00:00') }),
    scanOf(3, at('20:20:00'), [
      presentType(VELDSPAR, 18_000, { volumeM3: 1_800, unitPrice: 15, remainingValue: 270_000, recentRate: 700, recentEta: at('20:45:43'), trendRate: 600, trendEta: at('20:50:33'), maxDeviationUnits: 666.67, maxDeviationPercent: 2.22 }),
      presentType(SCORDITE, 5_000, { volumeM3: 750, unitPrice: 25, remainingValue: 125_000, etaUnknownReason: 'not_depleting' }),
    ], { authorName: 'Aria Solberg', totalVolumeM3: 2_550, remainingValue: 395_000, recentEta: at('20:45:43'), trendEta: at('20:50:33') }),
  ]
}

export function siteAfterThreeScans(overrides: Partial<MiningSite> = {}): MiningSite {
  return siteOf({
    firstScanAt: at('20:00:00'),
    lastScanAt: at('20:20:00'),
    observedSeconds: 1_200,
    firstVolumeM3: 3_750,
    firstValue: 575_000,
    averageTrendRate: 600,
    remainingValue: 395_000,
    recentEta: at('20:45:43'),
    trendEta: at('20:50:33'),
    lastScanNumber: 3,
    lastScanAuthorName: 'Aria Solberg',
    ...overrides,
  })
}

export const MINING_SITE_REQUIRED_KEYS = [
  'miningSite.status.active',
  'miningSite.status.closed',
  'miningSite.tier.normal',
  'miningSite.tier.orange',
  'miningSite.tier.red',
  'miningSite.tier.reached',
  'miningSite.tier.depleted',
  'miningSite.tier.unknown',
  'miningSite.eta.unknown',
  'miningSite.eta.depleted',
  'miningSite.single.hint',
  'miningSite.value.unknown',
  'miningSite.value.missingPrices',
  'miningSite.badge.notMined',
  'miningSite.badge.singleScan',
  'miningSite.badge.irregular',
  'miningSite.badge.depleted',
  'miningSite.rescan.due',
  'miningSite.rescan.hint',
  'miningSite.scan.added',
  'miningSite.scan.allDepleted',
  'miningSite.scan.errors.noLines',
  'miningSite.scan.errors.unitsInvalid',
  'miningSite.scan.errors.duplicate',
  'miningSite.feed.unknownAuthor',
  'miningSite.feed.shared',
  'miningSite.lastScan.justNow',
  'miningOperationSites.empty',
  'miningSite.create.labelRequired',
  'miningSite.history.empty',
  'miningSite.history.estimate',
  'miningSite.close.confirm',
  'miningOperation.tabs.sites',
] as const
