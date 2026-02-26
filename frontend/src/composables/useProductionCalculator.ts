import { formatDuration } from './useProjectTime'

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

export { formatDuration }
