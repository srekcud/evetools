import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import { usePushNotifications } from '@/composables/usePushNotifications'
import { useToast } from '@/composables/useToast'

export function useAccountDeletion() {
  const { t } = useI18n()
  const router = useRouter()
  const authStore = useAuthStore()
  const syncStore = useSyncStore()
  const { unsubscribeBrowser } = usePushNotifications()
  const { showToast } = useToast()

  const isDeleting = ref(false)

  async function deleteAccount(typedName: string): Promise<boolean> {
    isDeleting.value = true
    try {
      await apiRequest<null>('/me', { method: 'DELETE', body: JSON.stringify({ confirmation: typedName }) })
    } catch (e) {
      console.error('Account deletion failed:', e)
      showToast(t('characters.deleteAccount.failed'), 'error')
      isDeleting.value = false
      return false
    }

    await unsubscribeBrowser()
    syncStore.disconnect()
    authStore.logout()
    await router.push('/login')
    showToast(t('characters.deleteAccount.succeeded'), 'success')
    isDeleting.value = false
    return true
  }

  return {
    isDeleting,
    deleteAccount,
  }
}
