import { useI18n } from 'vue-i18n'
import type { MiningHaulerPlan } from '@/stores/miningOperation'
import { formatWhole } from '@/views/MiningOperation/miningOperationQuantity'
import { formatEveClock } from '@/views/MiningOperation/miningOperationTime'

export function useMiningHaulerText() {
  const { t, locale } = useI18n()

  function haulerNameOf(plan: MiningHaulerPlan): string {
    return plan.haulerName ?? t('miningOperation.unknownPilot')
  }

  function shipNameOf(plan: MiningHaulerPlan): string {
    return plan.boostShipName ?? t('miningOperation.haulerPlan.unknownShip')
  }

  function wholeOrUnknown(value: number | null): string {
    return value === null ? t('miningOperation.quantities.unknown') : formatWhole(value, locale.value)
  }

  function originText(plan: MiningHaulerPlan): string {
    return plan.emptiedAt === null ? t('miningOperation.haulerPlan.originStart') : t('miningOperation.haulerPlan.originEmptied', { time: formatEveClock(plan.emptiedAt) })
  }

  return { haulerNameOf, shipNameOf, wholeOrUnknown, originText }
}
