/**
 * Calculate the maximum number of runs achievable within a given duration.
 */
export function calculateMaxRuns(
  durationSeconds: number,
  timePerRunSeconds: number,
  productionLines: number,
): number {
  if (timePerRunSeconds <= 0 || productionLines <= 0) return 0
  const totalRunTime = durationSeconds * productionLines
  return Math.floor(totalRunTime / timePerRunSeconds)
}

/**
 * Calculate the total duration (seconds) to complete a given number of runs.
 */
export function calculateDurationForRuns(
  runs: number,
  timePerRunSeconds: number,
  productionLines: number,
): number {
  if (productionLines <= 0 || runs <= 0) return 0
  return Math.ceil(runs / productionLines) * timePerRunSeconds
}

export function formatDuration(seconds: number | null): string {
  if (seconds === null || seconds <= 0) return '-'

  const days = Math.floor(seconds / 86400)
  const hours = Math.floor((seconds % 86400) / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)

  if (days > 0) {
    if (hours > 0) {
      return `${days}j ${hours}h`
    }
    return `${days}j`
  } else if (hours > 0) {
    return `${hours}h ${minutes}m`
  } else {
    return `${minutes}m`
  }
}
