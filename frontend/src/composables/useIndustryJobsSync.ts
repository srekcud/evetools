import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSyncStore } from '@/stores/sync'
import type { CharacterSyncFailure } from '@/stores/industry/types'

export function useIndustryJobsSync(reload: () => void, isPaused: () => boolean = () => false) {
  const { t } = useI18n()
  const syncStore = useSyncStore()
  const errors = ref<string[]>([])
  const failures = ref<CharacterSyncFailure[]>([])

  watch(() => syncStore.getSyncProgress('industry-jobs'), (progress) => {
    if (!progress || isPaused()) return
    if (progress.status === 'completed') {
      const failedCharacters = progress.data?.failedCharacters
      if (Array.isArray(failedCharacters)) {
        failures.value = failedCharacters as CharacterSyncFailure[]
      }
      reload()
    } else if (progress.status === 'error') {
      errors.value = [...errors.value, progress.message || t('notifications.syncError')]
    }
  })

  function dismissErrors() {
    errors.value = []
  }

  function dismissFailures() {
    failures.value = []
  }

  return { errors, dismissErrors, failures, dismissFailures }
}
