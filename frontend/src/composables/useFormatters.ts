import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

// Module-level shared ref for date format (shared across all components)
export const dateFormat = ref<'eu' | 'us'>(
  (localStorage.getItem('evetools_date_format') as 'eu' | 'us') || 'eu'
)

const MS_PER_MINUTE = 60_000
const MINUTES_PER_HOUR = 60
const HOURS_PER_DAY = 24

function setDateFormat(format: 'eu' | 'us') {
  dateFormat.value = format
  localStorage.setItem('evetools_date_format', format)
}

/**
 * Composable for formatting values (ISK currency, dates)
 * Uses the current i18n locale for number formatting.
 * Uses the dateFormat setting (eu/us) for date formatting, independent of language.
 */
export function useFormatters() {
  const { locale, t } = useI18n()

  function getLocaleStr(): string {
    return locale.value === 'fr' ? 'fr-FR' : 'en-US'
  }

  function getDateLocaleStr(): string {
    return dateFormat.value === 'eu' ? 'fr-FR' : 'en-US'
  }

  function formatIsk(amount: number | undefined | null, decimals = 2): string {
    if (amount === undefined || amount === null) return '---'
    const abs = Math.abs(amount)
    const sign = amount < 0 ? '-' : ''
    const loc = getLocaleStr()
    if (abs >= 1_000_000_000) {
      return sign + (abs / 1_000_000_000).toLocaleString(loc, { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + ' B'
    }
    if (abs >= 1_000_000) {
      return sign + (abs / 1_000_000).toLocaleString(loc, { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + ' M'
    }
    if (abs >= 1_000) {
      return sign + (abs / 1_000).toLocaleString(loc, { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + ' K'
    }
    return amount.toLocaleString(loc, { maximumFractionDigits: 0 })
  }

  function formatIskFull(amount: number): string {
    return amount.toLocaleString(getLocaleStr(), { maximumFractionDigits: 0 }) + ' ISK'
  }

  function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString(getDateLocaleStr(), {
      day: '2-digit',
      month: '2-digit',
      year: '2-digit',
    })
  }

  function formatDateTime(dateStr: string): string {
    return new Date(dateStr).toLocaleString(getDateLocaleStr(), {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    })
  }

  function formatTimeSince(dateStr: string | null, now: Date = new Date()): string {
    if (!dateStr) return t('common.status.never')
    const diff = Math.max(0, now.getTime() - new Date(dateStr).getTime())
    const minutes = Math.floor(diff / MS_PER_MINUTE)
    if (minutes < 1) return t('common.time.justNow')
    if (minutes < MINUTES_PER_HOUR) return t('common.time.minutesAgo', { minutes })
    const hours = Math.floor(minutes / MINUTES_PER_HOUR)
    if (hours < HOURS_PER_DAY) return t('common.time.hoursAgo', { hours })
    return t('common.time.daysAgo', { days: Math.floor(hours / HOURS_PER_DAY) })
  }

  function formatDuration(seconds: number): string {
    if (seconds < 60) return `${seconds}s`
    const minutes = Math.floor(seconds / 60)
    if (minutes < 60) return `${minutes}m`
    const hours = Math.floor(minutes / 60)
    const remainingMinutes = minutes % 60
    if (hours < 24) {
      return remainingMinutes > 0 ? `${hours}h ${remainingMinutes}m` : `${hours}h`
    }
    const days = Math.floor(hours / 24)
    const remainingHours = hours % 24
    return remainingHours > 0 ? `${days}d ${remainingHours}h` : `${days}d`
  }

  function formatNumber(value: number | undefined | null, decimals = 2): string {
    if (value === undefined || value === null) return '---'
    return value.toLocaleString(getLocaleStr(), { maximumFractionDigits: decimals })
  }

  // Alias for backward compatibility
  const formatIskShort = formatIsk

  return {
    formatIsk,
    formatIskShort,
    formatIskFull,
    formatDate,
    formatDateTime,
    formatTimeSince,
    formatDuration,
    formatNumber,
    dateFormat,
    setDateFormat,
  }
}
