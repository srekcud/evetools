import { useI18n } from 'vue-i18n'

/**
 * Composable for formatting values (ISK currency, dates)
 * Uses the current i18n locale for number/date formatting.
 */
export function useFormatters() {
  const { locale } = useI18n()

  function getLocaleStr(): string {
    return locale.value === 'fr' ? 'fr-FR' : 'en-US'
  }

  function formatIsk(amount: number | undefined | null, decimals = 2): string {
    if (amount === undefined || amount === null) return '---'
    if (amount >= 1_000_000_000) {
      return (amount / 1_000_000_000).toFixed(decimals) + ' B'
    }
    if (amount >= 1_000_000) {
      return (amount / 1_000_000).toFixed(decimals) + ' M'
    }
    if (amount >= 1_000) {
      return (amount / 1_000).toFixed(decimals) + ' K'
    }
    return amount.toFixed(0)
  }

  function formatIskFull(amount: number): string {
    return amount.toLocaleString(getLocaleStr(), { maximumFractionDigits: 0 }) + ' ISK'
  }

  function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString(getLocaleStr(), {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    })
  }

  function formatDateTime(dateStr: string): string {
    return new Date(dateStr).toLocaleString(getLocaleStr(), {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    })
  }

  function formatTimeSince(dateStr: string | null): string {
    if (!dateStr) return 'Never'
    const date = new Date(dateStr)
    const now = new Date()
    const diff = now.getTime() - date.getTime()
    const minutes = Math.floor(diff / 60000)
    if (minutes < 60) return `${minutes}m ago`
    const hours = Math.floor(minutes / 60)
    if (hours < 24) return `${hours}h ago`
    return `${Math.floor(hours / 24)}d ago`
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
  }
}
