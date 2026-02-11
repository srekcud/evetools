/**
 * Backward-compatibility utilities.
 * Enriches new API step data with legacy computed fields
 * so existing Vue components work without modification.
 */
import { isValidationError } from '@/services/api'
import type { IndustryProjectStep, IndustryProject } from './types'

/**
 * Format an API error into a user-facing message.
 */
export function formatErrorMessage(e: unknown, defaultMessage: string): string {
  if (isValidationError(e)) {
    return e.violations.map(v => v.message).join(' | ')
  }
  if (e instanceof Error) {
    return e.message
  }
  return defaultMessage
}

/**
 * Enrich a step with backward-compatible computed fields.
 * Call this after receiving step data from the API.
 */
export function enrichStep(step: IndustryProjectStep): IndustryProjectStep {
  // Effective stock: purchasedQuantity has priority over inStockQuantity (paste)
  const effectiveStock = step.purchasedQuantity > 0 ? step.purchasedQuantity : step.inStockQuantity
  step.inStock = effectiveStock >= step.quantity && effectiveStock > 0

  // isSplit: derived from splitGroupId
  step.isSplit = step.splitGroupId !== null

  // ESI job fields: derived from jobMatches array
  if (step.jobMatches && step.jobMatches.length > 0) {
    const firstMatch = step.jobMatches[0]
    step.esiJobId = firstMatch.esiJobId
    step.esiJobCost = firstMatch.cost
    step.esiJobStatus = firstMatch.status
    step.esiJobEndDate = firstMatch.endDate
    step.esiJobRuns = firstMatch.runs
    step.esiJobCharacterName = firstMatch.characterName
    step.esiJobsCount = step.jobMatches.length
    step.esiJobsTotalRuns = step.jobMatches.reduce((sum, m) => sum + m.runs, 0)
    step.esiJobsActiveRuns = step.jobMatches
      .filter(m => m.status === 'active')
      .reduce((sum, m) => sum + m.runs, 0)
    step.esiJobsDeliveredRuns = step.jobMatches
      .filter(m => m.status === 'delivered')
      .reduce((sum, m) => sum + m.runs, 0)
    step.similarJobs = step.jobMatches
  } else {
    step.esiJobId = null
    step.esiJobCost = null
    step.esiJobStatus = null
    step.esiJobEndDate = null
    step.esiJobRuns = null
    step.esiJobCharacterName = null
    step.esiJobsCount = null
    step.esiJobsTotalRuns = null
    step.esiJobsActiveRuns = null
    step.esiJobsDeliveredRuns = null
    step.similarJobs = []
  }

  // manualJobData: derived from jobMatchMode
  step.manualJobData = step.jobMatchMode === 'manual'

  // Structure fields
  step.recommendedStructureName = step.structureConfigName
  step.structureBonus = step.structureMaterialBonus

  // Duration estimate
  if (step.timePerRun !== null && step.runs > 0) {
    step.estimatedDurationDays = (step.timePerRun * step.runs) / 86400
  } else {
    step.estimatedDurationDays = null
  }

  return step
}

/**
 * Enrich all steps in a project.
 */
export function enrichProject(project: IndustryProject): IndustryProject {
  if (project.steps) {
    project.steps = project.steps.map(enrichStep)
  }
  return project
}
