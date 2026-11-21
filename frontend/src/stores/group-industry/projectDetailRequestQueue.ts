import { defineStore } from 'pinia'

export const PROJECT_DETAIL_READ_WAIT_LIMIT_MS = 40_000

export const useProjectDetailRequestQueueStore = defineStore('group-project-detail-request-queue', () => {
  const previousReadSettledByProjectId = new Map<string, Promise<void>>()

  function settledOrGivenUpOn(read: Promise<unknown>): Promise<void> {
    return new Promise<void>((resolve) => {
      const giveUp = setTimeout(resolve, PROJECT_DETAIL_READ_WAIT_LIMIT_MS)
      const release = (): void => {
        clearTimeout(giveUp)
        resolve()
      }
      read.then(release, release)
    })
  }

  function runAfterPreviousReadsOf<T>(projectId: string, read: () => Promise<T>): Promise<T> {
    const result = (previousReadSettledByProjectId.get(projectId) ?? Promise.resolve()).then(read)
    previousReadSettledByProjectId.set(projectId, settledOrGivenUpOn(result))

    return result
  }

  return { runAfterPreviousReadsOf }
})
