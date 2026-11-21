import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { AvailableJob, BomItem, CharacterMatchFailure, LineMatchFailure } from '@/stores/group-industry/types'

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

  async function linkJob(projectId: string, esiJobId: number): Promise<boolean> {
    const line = linkScreenLine.value
    if (line === null) return false
    linkingEsiJobId.value = esiJobId
    jobLinkError.value = null
    try {
      await projectStore.linkJob(projectId, line.id, esiJobId)
      closeLinkScreen()
      return true
    } catch (e) {
      jobLinkError.value = failureMessage(e, 'groupIndustry.jobs.linkError')
      return false
    } finally {
      linkingEsiJobId.value = null
    }
  }

  async function unlinkJob(projectId: string, matchId: string): Promise<boolean> {
    jobLinkError.value = null
    try {
      await projectStore.unlinkJob(projectId, matchId)
      return true
    } catch (e) {
      jobLinkError.value = failureMessage(e, 'groupIndustry.jobs.unlinkError')
      return false
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
