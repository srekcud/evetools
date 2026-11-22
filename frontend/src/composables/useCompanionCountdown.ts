import { useI18n } from 'vue-i18n'
import { countdownPartsOf } from '@/components/group-industry/companionTasks'

const TIME_UNIT_DIGITS = 2

function padTimeUnit(value: number): string {
  return String(value).padStart(TIME_UNIT_DIGITS, '0')
}

export function useCompanionCountdown() {
  const { t } = useI18n()

  function countdownText(remainingMs: number): string {
    const { days, hours, minutes } = countdownPartsOf(remainingMs)
    return days > 0
      ? t('groupIndustry.companion.countdown.days', { days, hours: padTimeUnit(hours) })
      : t('groupIndustry.companion.countdown.clock', { hours: padTimeUnit(hours), minutes: padTimeUnit(minutes) })
  }

  return { countdownText }
}
