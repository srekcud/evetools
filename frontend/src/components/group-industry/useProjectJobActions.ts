import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { ApiError } from '@/services/api'
import type { AvailableJob, BomItem, CharacterMatchFailure, LineMatchFailure } from '@/stores/group-industry/types'
import { linkRefusalOf } from './jobLinking'

export function useProjectJobActions() {
  const { t, te } = useI18n()
  const projectStore = useGroupProjectStore()

  const isMatchingJobs = ref(false)
  const matchJobsError = ref<string | null>(null)
  const failedCharacters = ref<CharacterMatchFailure[]>([])
  const failedLines = ref<LineMatchFailure[]>([])

  const linkScreenLine = ref<BomItem | null>(null)
  const availableJobs = ref<AvailableJob[]>([])
  const isLoadingAvailableJobs = ref(false)
  const linkingEsiJobId = ref<number | null>(null)
  const jobLinkError = ref<string | null>(null)

  function characterFailureReason(failure: CharacterMatchFailure): string {
    const key = `industry.syncFailures.reasons.${failure.reason}`
    return te(key) ? t(key, { status: failure.statusCode }) : failure.reason
  }

  function failureMessage(error: unknown, fallbackKey: string): string {
    return error instanceof Error ? error.message : t(fallbackKey)
  }

  function linkFailureMessage(error: unknown): string {
    if (!(error instanceof ApiError)) return failureMessage(error, 'groupIndustry.jobs.linkError')
    const refusal = linkRefusalOf(error.message)
    return refusal === null
      ? t('groupIndustry.jobs.linkError')
      : t(`groupIndustry.jobs.linkRefusals.${refusal.key}`, { runs: refusal.runs ?? 0 })
  }

  async function matchJobs(projectId: string): Promise<boolean> {
    isMatchingJobs.value = true
    matchJobsError.value = null
    try {
      const failures = await projectStore.matchJobs(projectId)
      failedCharacters.value = failures.failedCharacters
      failedLines.value = failures.failedLines
      return true
    } catch (e) {
      failedCharacters.value = []
      failedLines.value = []
      matchJobsError.value = failureMessage(e, 'groupIndustry.jobs.matchError')
      return false
    } finally {
      isMatchingJobs.value = false
    }
  }

  async function openLinkScreen(projectId: string, line: BomItem): Promise<void> {
    linkScreenLine.value = line
    jobLinkError.value = null
    isLoadingAvailableJobs.value = true
    try {
      availableJobs.value = await projectStore.fetchAvailableJobs(projectId)
    } catch (e) {
      availableJobs.value = []
      jobLinkError.value = failureMessage(e, 'groupIndustry.jobs.availableJobsError')
    } finally {
      isLoadingAvailableJobs.value = false
    }
  }

  function closeLinkScreen(): void {
    linkScreenLine.value = null
  }

  async function linkJob(projectId: string, esiJobId: number, runs?: number): Promise<void> {
    const line = linkScreenLine.value
    if (line === null) return
    linkingEsiJobId.value = esiJobId
    jobLinkError.value = null
    try {
      await projectStore.linkJob(projectId, line.id, esiJobId, runs)
      closeLinkScreen()
    } catch (e) {
      jobLinkError.value = linkFailureMessage(e)
    } finally {
      linkingEsiJobId.value = null
    }
  }

  async function unlinkJob(projectId: string, matchId: string): Promise<void> {
    jobLinkError.value = null
    try {
      await projectStore.unlinkJob(projectId, matchId)
    } catch (e) {
      jobLinkError.value = failureMessage(e, 'groupIndustry.jobs.unlinkError')
    }
  }

  return {
    isMatchingJobs,
    matchJobsError,
    failedCharacters,
    failedLines,
    linkScreenLine,
    availableJobs,
    isLoadingAvailableJobs,
    linkingEsiJobId,
    jobLinkError,
    characterFailureReason,
    matchJobs,
    openLinkScreen,
    closeLinkScreen,
    linkJob,
    unlinkJob,
  }
}
