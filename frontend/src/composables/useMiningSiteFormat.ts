import { useI18n } from 'vue-i18n'
import { formatWhole } from '@/views/MiningOperation/miningOperationQuantity'
import { formatEveClock, formatEveDateTime } from '@/views/MiningOperation/miningOperationTime'

export const UNKNOWN_DASH = '–'

export function useMiningSiteFormat() {
  const { t, locale } = useI18n()

  function etaText(eta: string | null | undefined): string {
    return eta === null || eta === undefined ? t('miningSite.eta.unknown') : formatEveClock(eta)
  }

  function dateTimeText(moment: string): string {
    return formatEveDateTime(moment)
  }

  function wholeText(value: number): string {
    return formatWhole(value, locale.value)
  }

  function valueText(value: number | null | undefined): string {
    return value === null || value === undefined ? t('miningSite.value.unknown') : formatWhole(value, locale.value)
  }

  return { etaText, dateTimeText, wholeText, valueText }
}
