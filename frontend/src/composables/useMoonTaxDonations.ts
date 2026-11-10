import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiRequest } from '@/services/api'
import { useSyncStore } from '@/stores/sync'
import { withoutKey, type MoonTaxDonationEntry } from '@/composables/useMoonTax'

const MOON_TAX_ENDPOINT = '/mining/moon-tax'

export function useMoonTaxDonations(corporationId: number) {
  const { t } = useI18n()
  const syncStore = useSyncStore()
  const donationsEndpoint = `${MOON_TAX_ENDPOINT}/${corporationId}/donations`

  const donations = ref<MoonTaxDonationEntry[]>([])
  const isLoading = ref(false)
  const hasLoadError = ref(false)
  const busyJournalId = ref<number | null>(null)
  const rowErrors = ref<Record<number, string>>({})
  let latestRequest = 0

  async function load(): Promise<void> {
    const request = ++latestRequest
    isLoading.value = true
    try {
      const payload = await apiRequest<unknown>(donationsEndpoint)
      if (request !== latestRequest) return
      if (!Array.isArray(payload)) throw new Error('Unexpected moon tax donations payload')
      donations.value = payload as MoonTaxDonationEntry[]
      hasLoadError.value = false
    } catch {
      if (request === latestRequest) hasLoadError.value = true
    } finally {
      if (request === latestRequest) isLoading.value = false
    }
  }

  async function attribute(journalId: number, characterId: number): Promise<boolean> {
    busyJournalId.value = journalId
    rowErrors.value = withoutKey(rowErrors.value, journalId)
    try {
      await apiRequest(`${donationsEndpoint}/${journalId}/attribute`, { method: 'POST', body: JSON.stringify({ characterId }) })
    } catch (error) {
      const message = error instanceof Error ? error.message : t('ledger.mining.moonTax.requestFailed')
      rowErrors.value = { ...rowErrors.value, [journalId]: message }
      return false
    } finally {
      busyJournalId.value = null
    }
    await load()
    return true
  }

  watch(() => syncStore.moonTaxProgress, progress => {
    if (progress?.status === 'completed') void load()
  })

  return { donations, isLoading, hasLoadError, busyJournalId, rowErrors, load, attribute }
}
