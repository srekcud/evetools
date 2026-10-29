<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from '@/components/common/DataTable.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { GroupSale, GroupSaleSuggestion, RecordSaleFromWalletTransactionInput } from '@/stores/group-industry/types'

const props = defineProps<{
  projectId: string
  recordingDisabled?: boolean
}>()

const { t } = useI18n()
const store = useGroupDistributionStore()
const { formatNumber, formatDate } = useFormatters()

const enteredQuantities = ref<Record<string, number | ''>>({})
const recordingTransactionId = ref<string | null>(null)
const linkingError = ref<string | null>(null)

function duplicatedSalesOf(suggestion: GroupSaleSuggestion): GroupSale[] {
  return store.sales.filter(sale => suggestion.possibleDuplicateSaleIds.includes(sale.id))
}

async function recordAsSale(suggestion: GroupSaleSuggestion): Promise<void> {
  const input: RecordSaleFromWalletTransactionInput = { transactionId: suggestion.transactionId }
  const enteredQuantity = enteredQuantities.value[suggestion.transactionId]
  if (typeof enteredQuantity === 'number') input.quantity = enteredQuantity

  recordingTransactionId.value = suggestion.transactionId
  linkingError.value = null
  try {
    await store.recordSaleFromWalletTransaction(props.projectId, input)
  } catch (e) {
    linkingError.value = e instanceof Error ? e.message : t('groupIndustry.saleSuggestions.recordFailed')
    return
  } finally {
    recordingTransactionId.value = null
  }
  await store.fetchSaleSuggestions(props.projectId)
}

onMounted(() => {
  store.fetchSaleSuggestions(props.projectId)
})
</script>

<template>
  <div class="mb-8">
    <h2 class="text-lg font-semibold text-slate-100 mb-4">{{ t('groupIndustry.saleSuggestions.title') }}</h2>

    <div v-if="store.loadingSaleSuggestions" class="flex items-center justify-center py-12">
      <LoadingSpinner size="lg" class="text-cyan-400" />
    </div>

    <div
      v-else-if="store.saleSuggestionsError"
      class="bg-red-500/10 border border-red-500/20 rounded-xl p-4"
    >
      <p class="text-sm text-red-400">{{ store.saleSuggestionsError }}</p>
    </div>

    <div
      v-else-if="store.saleSuggestions.length === 0"
      class="bg-slate-900/80 rounded-xl border border-slate-800 p-12 text-center"
    >
      <p class="text-sm text-slate-500">{{ t('groupIndustry.saleSuggestions.empty') }}</p>
    </div>

    <div v-else class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
      <DataTable>
        <table class="w-full text-sm">
          <thead>
            <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-800">
              <th class="text-left py-3 px-6">{{ t('groupIndustry.saleSuggestions.date') }}</th>
              <th class="text-left py-3 px-3">{{ t('groupIndustry.saleSuggestions.character') }}</th>
              <th class="text-right py-3 px-3">{{ t('groupIndustry.saleSuggestions.quantity') }}</th>
              <th class="text-right py-3 px-3">{{ t('groupIndustry.saleSuggestions.unitPrice') }}</th>
              <th class="text-right py-3 px-3">{{ t('groupIndustry.saleSuggestions.total') }}</th>
              <th class="py-3 px-6"><span class="sr-only">{{ t('groupIndustry.sales.actionsColumn') }}</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800/30">
            <tr
              v-for="suggestion in store.saleSuggestions"
              :key="suggestion.transactionId"
              class="hover:bg-slate-800/30"
            >
              <td class="py-3 px-6 text-slate-300">{{ formatDate(suggestion.date) }}</td>
              <td class="py-3 px-3 text-slate-300">{{ suggestion.sellerCharacterName }}</td>
              <td class="py-3 px-3 text-right font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                {{ formatNumber(suggestion.quantity, 0) }}
              </td>
              <td class="py-3 px-3 text-right font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                {{ formatNumber(suggestion.unitPrice, 0) }}
              </td>
              <td class="py-3 px-3 text-right font-mono text-emerald-400" style="font-variant-numeric: tabular-nums;">
                {{ formatNumber(suggestion.totalPrice, 0) }}
              </td>
              <td class="py-3 px-6">
                <div class="flex items-center justify-end gap-3 whitespace-nowrap">
                  <div v-if="suggestion.possibleDuplicateSaleIds.length > 0" class="flex flex-col items-end gap-1">
                    <span
                      :title="t('groupIndustry.saleSuggestions.possibleDuplicateHint')"
                      class="text-xs px-2 py-0.5 rounded bg-amber-500/10 text-amber-400 border border-amber-500/20"
                    >{{ t('groupIndustry.saleSuggestions.possibleDuplicate') }}</span>
                    <span
                      v-for="sale in duplicatedSalesOf(suggestion)"
                      :key="sale.id"
                      class="text-xs text-amber-400/80"
                    >{{ t('groupIndustry.saleSuggestions.duplicateOf', { date: formatDate(sale.soldAt), total: formatNumber(sale.totalPrice, 0) }) }}</span>
                  </div>
                  <input
                    v-model.number="enteredQuantities[suggestion.transactionId]"
                    type="number"
                    min="1"
                    :max="suggestion.quantity"
                    :placeholder="String(suggestion.quantity)"
                    :aria-label="t('groupIndustry.saleSuggestions.quantityLabel')"
                    class="w-24 bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-sm text-slate-200 font-mono focus:border-cyan-500 focus:outline-none"
                  />
                  <button
                    :disabled="recordingDisabled || recordingTransactionId !== null"
                    class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 disabled:bg-slate-700 disabled:text-slate-500 rounded-lg text-white text-xs font-medium transition-colors"
                    @click="recordAsSale(suggestion)"
                  >{{ t('groupIndustry.saleSuggestions.record') }}</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </DataTable>
    </div>

    <div v-if="linkingError" class="mt-4 bg-red-500/10 border border-red-500/20 rounded-xl p-4">
      <p class="text-sm text-red-400">{{ linkingError }}</p>
    </div>
  </div>
</template>
