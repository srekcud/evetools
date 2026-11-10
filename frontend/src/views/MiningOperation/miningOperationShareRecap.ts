import { formatIskShort } from '@/composables/useIskParser'
import type { MiningOperation, MiningOperationLedger, MiningOperationValuation } from '@/stores/miningOperation'
import { formatEveClock, formatEveDateTime, formatDuration } from '@/views/MiningOperation/miningOperationTime'
import { missingTypeNames } from '@/views/MiningOperation/miningOperationValuation'

const TOP_ORES_COUNT = 3
const REFERENCE_HEXADECIMAL_LENGTH = 6
const SEGMENT_SEPARATOR = ' · '

export interface ShareRecapOre {
  typeName: string
  value: number | null
}

export interface ShareRecap {
  label: string
  solarSystemName: string | null
  period: string | null
  totalValue: number | null
  unknownCauses: string
  iskPerHour: number | null
  durationSeconds: number | null
  participantsCount: number
  sitesCount: number | null
  topOres: ShareRecapOre[] | null
  eachForThemselves: boolean
  boosterCutPercent: number
  boosterCut: number | null
  reference: string
}

type Translate = (key: string, params?: Record<string, unknown>) => string

function referenceOf(operationId: string): string {
  return `OP-${operationId.replace(/-/g, '').slice(-REFERENCE_HEXADECIMAL_LENGTH).toUpperCase()}`
}

function periodOf(operation: MiningOperation): string | null {
  if (operation.startedAt === null || operation.endedAt === null) return null
  return `${formatEveDateTime(operation.startedAt)} → ${formatEveClock(operation.endedAt)} EVE`
}

function minedValueByType(valuation: MiningOperationValuation, ledger: MiningOperationLedger): ShareRecapOre[] {
  const quantityByType = new Map<number, { typeName: string; quantity: number }>()
  for (const participant of ledger.participants) {
    for (const mined of participant.quantities) {
      const known = quantityByType.get(mined.typeId)
      quantityByType.set(mined.typeId, { typeName: mined.typeName, quantity: known === undefined ? mined.quantity : known.quantity + mined.quantity })
    }
  }
  return [...quantityByType.entries()].map(([typeId, mined]) => {
    const unitPrice = valuation.unitPrices.find(price => price.typeId === typeId)?.unitPrice ?? null
    return { typeName: mined.typeName, value: unitPrice === null ? null : mined.quantity * unitPrice }
  })
}

function rankByValue(ores: ShareRecapOre[]): ShareRecapOre[] {
  const valued = ores.filter((ore): ore is { typeName: string; value: number } => ore.value !== null).sort((first, second) => second.value - first.value)
  return [...valued, ...ores.filter(ore => ore.value === null)].slice(0, TOP_ORES_COUNT)
}

export function buildShareRecap(operation: MiningOperation, valuation: MiningOperationValuation, ledger: MiningOperationLedger, unknownPilotLabel: string, sitesCount: number | null = null): ShareRecap {
  const someQuantityUnknown = ledger.participants.some(participant => participant.state !== 'known')
  const causes = [missingTypeNames(valuation), someQuantityUnknown ? unknownPilotLabel : ''].filter(cause => cause !== '')
  return {
    label: operation.label,
    solarSystemName: operation.solarSystemName,
    period: periodOf(operation),
    totalValue: valuation.totalValue,
    unknownCauses: causes.join(', '),
    iskPerHour: valuation.iskPerHour,
    durationSeconds: valuation.durationSeconds,
    participantsCount: operation.participantsCount,
    sitesCount,
    topOres: someQuantityUnknown ? null : rankByValue(minedValueByType(valuation, ledger)),
    eachForThemselves: operation.splitMode === 'each_for_themselves',
    boosterCutPercent: valuation.settings.boosterCutPercent,
    boosterCut: valuation.boosterCut,
    reference: referenceOf(operation.id),
  }
}

function formatIsk(value: number | null, t: Translate): string {
  return value === null ? t('miningOperation.quantities.unknown') : formatIskShort(Math.round(value))
}

export function shareRecapLines(recap: ShareRecap, basis: string, t: Translate): string[] {
  const identity = [recap.label, recap.solarSystemName, recap.period].filter((segment): segment is string => segment !== null)
  const valueLine = recap.totalValue === null
    ? t('miningOperation.shareRecap.text.valueUnknown', { causes: recap.unknownCauses })
    : t('miningOperation.shareRecap.text.value', { value: formatIskShort(Math.round(recap.totalValue)) })
  const figures = [
    recap.iskPerHour === null
      ? t('miningOperation.shareRecap.text.iskPerHourUnknown')
      : t('miningOperation.shareRecap.text.iskPerHour', { value: formatIskShort(Math.round(recap.iskPerHour)) }),
    recap.durationSeconds === null
      ? t('miningOperation.shareRecap.text.durationUnknown')
      : t('miningOperation.shareRecap.text.duration', { duration: formatDuration(recap.durationSeconds) }),
    t('miningOperation.shareRecap.text.participants', { count: recap.participantsCount }),
  ]
  if (recap.sitesCount !== null && recap.sitesCount > 0) {
    figures.push(t('miningOperation.shareRecap.text.sites', { count: recap.sitesCount }))
  }
  const lines = [identity.join(SEGMENT_SEPARATOR), `${valueLine}${SEGMENT_SEPARATOR}${basis}`, figures.join(SEGMENT_SEPARATOR)]
  if (recap.topOres === null) {
    lines.push(t('miningOperation.shareRecap.text.topOresUnknown'))
  } else if (recap.topOres.length > 0) {
    lines.push(t('miningOperation.shareRecap.text.topOres', { ores: recap.topOres.map(ore => `${ore.typeName} ${formatIsk(ore.value, t)}`).join(SEGMENT_SEPARATOR) }))
  }
  if (recap.eachForThemselves) {
    lines.push(t('miningOperation.shareRecap.text.eachForThemselves'), t('miningOperation.shareRecap.text.footerEachForThemselves'))
  } else {
    if (recap.boosterCutPercent > 0) {
      lines.push(t('miningOperation.shareRecap.text.boosterShare', { percent: recap.boosterCutPercent, amount: formatIsk(recap.boosterCut, t) }))
    }
    lines.push(t('miningOperation.shareRecap.text.footer', { reference: recap.reference }))
  }
  return lines
}
