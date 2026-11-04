const MINUTES_PER_HOUR = 60

export function formatSeasonDuration(minutes: number): string {
  if (minutes < MINUTES_PER_HOUR) return `${minutes} min`
  const hours = Math.floor(minutes / MINUTES_PER_HOUR)
  const remainingMinutes = minutes % MINUTES_PER_HOUR
  return `${hours} h ${String(remainingMinutes).padStart(2, '0')} min`
}
