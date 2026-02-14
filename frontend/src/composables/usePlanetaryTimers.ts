import { ref, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { Pin } from '@/stores/planetary'

export interface TimerInfo {
  remaining: number
  formatted: string
  status: 'active' | 'expiring' | 'expired'
}

export interface TimerColors {
  dot: string
  text: string
  pulse: string
}

export function usePlanetaryTimers() {
  const { t } = useI18n()
  const { formatNumber } = useFormatters()

  const now = ref(new Date())
  let timerInterval: ReturnType<typeof setInterval> | null = null

  onMounted(() => {
    timerInterval = setInterval(() => {
      now.value = new Date()
    }, 1000)
  })

  onUnmounted(() => {
    if (timerInterval) {
      clearInterval(timerInterval)
    }
  })

  function getTimerInfo(expiryTime: string | null): TimerInfo {
    if (!expiryTime) return { remaining: -1, formatted: 'N/A', status: 'expired' }

    const expiry = new Date(expiryTime)
    const diff = expiry.getTime() - now.value.getTime()

    if (diff <= 0) {
      return { remaining: 0, formatted: t('pi.legend.expired').toUpperCase(), status: 'expired' }
    }

    const totalSeconds = Math.floor(diff / 1000)
    const hours = Math.floor(totalSeconds / 3600)
    const minutes = Math.floor((totalSeconds % 3600) / 60)
    const seconds = totalSeconds % 60

    const formatted = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
    const status = hours < 24 ? 'expiring' : 'active'

    return { remaining: totalSeconds, formatted, status }
  }

  function getTimerColorClasses(status: 'active' | 'expiring' | 'expired'): TimerColors {
    switch (status) {
      case 'active':
        return { dot: 'bg-emerald-400', text: 'text-emerald-400', pulse: '' }
      case 'expiring':
        return { dot: 'bg-amber-500', text: 'text-amber-400', pulse: 'timer-urgent' }
      case 'expired':
        return { dot: 'bg-red-500', text: 'text-red-400', pulse: 'animate-pulse' }
    }
  }

  function formatCycleTime(seconds: number | null): string {
    if (!seconds) return '-'
    const minutes = Math.floor(seconds / 60)
    if (minutes < 60) return `${minutes} min`
    const hours = Math.floor(minutes / 60)
    const remainingMin = minutes % 60
    return remainingMin > 0 ? `${hours}h ${remainingMin}min` : `${hours}h`
  }

  function formatDailyOutput(pin: Pin): string {
    if (!pin.extractorQtyPerCycle || !pin.extractorCycleTime) return '-'
    const cyclesPerHour = 3600 / pin.extractorCycleTime
    const hourly = Math.round(pin.extractorQtyPerCycle * cyclesPerHour)
    return t('pi.colony.maxPerHour', { value: formatNumber(hourly) })
  }

  function isStaleData(cachedAt: string): boolean {
    const cachedDate = new Date(cachedAt)
    const diffMs = now.value.getTime() - cachedDate.getTime()
    return diffMs > 60 * 60 * 1000
  }

  return {
    now,
    getTimerInfo,
    getTimerColorClasses,
    formatCycleTime,
    formatDailyOutput,
    isStaleData,
  }
}
