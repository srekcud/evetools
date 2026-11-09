const MS_PER_SECOND = 1000
const SECONDS_PER_MINUTE = 60
const SECONDS_PER_HOUR = 3600
const UNIT_WIDTH = 2

function padUnit(value: number): string {
  return String(value).padStart(UNIT_WIDTH, '0')
}

export function formatCountdown(endsAt: string, now: number): string | null {
  const remainingMs = Date.parse(endsAt) - now
  if (remainingMs <= 0) return null
  const remainingSeconds = Math.ceil(remainingMs / MS_PER_SECOND)
  const hours = Math.floor(remainingSeconds / SECONDS_PER_HOUR)
  const minutes = Math.floor((remainingSeconds % SECONDS_PER_HOUR) / SECONDS_PER_MINUTE)
  const seconds = remainingSeconds % SECONDS_PER_MINUTE
  return `${padUnit(hours)}:${padUnit(minutes)}:${padUnit(seconds)}`
}
