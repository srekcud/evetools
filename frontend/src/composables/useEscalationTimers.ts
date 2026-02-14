import { ref, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'

export type TimerZone = 'safe' | 'warning' | 'danger' | 'expired'

export interface EscalationTimerInfo {
  text: string
  percent: number
  zone: TimerZone
}

export function useEscalationTimers() {
  const { t } = useI18n()

  const now = ref(Date.now())
  let timerInterval: ReturnType<typeof setInterval> | null = null

  onMounted(() => {
    timerInterval = setInterval(() => {
      now.value = Date.now()
    }, 1000)
  })

  onUnmounted(() => {
    if (timerInterval) clearInterval(timerInterval)
  })

  function getTimerInfo(expiresAt: string): EscalationTimerInfo {
    const remaining = new Date(expiresAt).getTime() - now.value
    if (remaining <= 0) {
      return { text: t('escalations.timer.expired'), percent: 0, zone: 'expired' }
    }

    const totalSeconds = Math.floor(remaining / 1000)
    const days = Math.floor(totalSeconds / 86400)
    const hours = Math.floor((totalSeconds % 86400) / 3600)
    const minutes = Math.floor((totalSeconds % 3600) / 60)
    const seconds = totalSeconds % 60

    const pad = (n: number) => String(n).padStart(2, '0')
    const text = days > 0
      ? `${days}j ${pad(hours)}h ${pad(minutes)}m`
      : `${pad(hours)}h ${pad(minutes)}m ${pad(seconds)}s`

    // Progress bar based on 24h max (not 72h)
    const PROGRESS_BAR_HOURS = 24
    const percent = Math.max(0, Math.min(100, (remaining / (PROGRESS_BAR_HOURS * 3600000)) * 100))
    const zone: TimerZone = percent > 50 ? 'safe' : percent > 20 ? 'warning' : 'danger'

    return { text, percent, zone }
  }

  function timerBarColor(zone: string): string {
    if (zone === 'safe') return 'bg-cyan-500'
    if (zone === 'warning') return 'bg-amber-500'
    return 'bg-red-500'
  }

  function timerTextColor(zone: string): string {
    if (zone === 'safe') return 'text-cyan-400'
    if (zone === 'warning') return 'text-amber-400'
    return 'text-red-400'
  }

  return {
    now,
    getTimerInfo,
    timerBarColor,
    timerTextColor,
  }
}
