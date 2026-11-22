import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'

const DETAIL_KEY_PREFIX = 'groupIndustry.flow.detail'
const NO_BREAK_SPACE = '\u00a0'
const PERCENT_DECIMALS = 1
const VOLUME_DECIMALS = 1

export function useFlowDetailFormat() {
  const { t } = useI18n()
  const { formatNumber, formatIsk, formatDateTime } = useFormatters()

  function detailText(key: string, params: Record<string, unknown> = {}): string {
    return t(`${DETAIL_KEY_PREFIX}.${key}`, params)
  }

  function unknownLabel(): string {
    return detailText('unknown')
  }

  function numberOrUnknown(value: number | null | undefined, decimals = 0): string {
    return value === null || value === undefined ? unknownLabel() : formatNumber(value, decimals)
  }

  function iskOrUnknown(value: number | null | undefined): string {
    return value === null || value === undefined ? unknownLabel() : formatIsk(value)
  }

  function textOrUnknown(value: string | null | undefined): string {
    return value === null || value === undefined || value === '' ? unknownLabel() : value
  }

  function dateTimeOrUnknown(value: string | null | undefined): string {
    return value === null || value === undefined ? unknownLabel() : formatDateTime(value)
  }

  function percentOrUnknown(value: number | null | undefined): string {
    return value === null || value === undefined ? unknownLabel() : `${formatNumber(value, PERCENT_DECIMALS)}${NO_BREAK_SPACE}%`
  }

  function probabilityOrUnknown(probability: number | null | undefined): string {
    return probability === null || probability === undefined ? unknownLabel() : percentOrUnknown(probability * 100)
  }

  function cubicMetersOrUnknown(value: number | null | undefined): string {
    return value === null || value === undefined ? unknownLabel() : `${formatNumber(value, VOLUME_DECIMALS)}${NO_BREAK_SPACE}m³`
  }

  return { detailText, numberOrUnknown, iskOrUnknown, textOrUnknown, dateTimeOrUnknown, percentOrUnknown, probabilityOrUnknown, cubicMetersOrUnknown }
}
