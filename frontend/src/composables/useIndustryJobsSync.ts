import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSyncStore } from '@/stores/sync'

export function useIndustryJobsSync(reload: () => void, isPaused: () => boolean = () => false) {
  const { t } = useI18n()
  const syncStore = useSyncStore()
  const errors = ref<string[]>([])

  watch(() => syncStore.getSyncProgress('industry-jobs'), (progress) => {
    if (!progress || isPaused()) return
    if (progress.status === 'completed') {
      reload()
    } else if (progress.status === 'error') {
      errors.value = [...errors.value, progress.message || t('notifications.syncError')]
    }
  })

  function dismissErrors() {
    errors.value = []
  }

  return { errors, dismissErrors }
}
