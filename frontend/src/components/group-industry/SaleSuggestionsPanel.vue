<script setup lang="ts">
import { computed, ref, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from '@/components/common/DataTable.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import { useFormatters } from '@/composables/useFormatters'
import WalletReadingChips from '@/components/group-industry/WalletReadingChips.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useSyncStore } from '@/stores/sync'
import type { GroupSale, GroupSaleSuggestion, RecordSaleFromWalletTransactionInput } from '@/stores/group-industry/types'

const props = defineProps<{
  projectId: string
  recordingDisabled?: boolean
}>()

const { t } = useI18n()
const store = useGroupDistributionStore()
const syncStore = useSyncStore()
const { formatNumber, formatDate, formatIsk } = useFormatters()

const MIN_TRANSACTIONS_FOR_RECORD_ALL = 2

type RecordAllFailure = { transactionId: string; date: string; sellerCharacterName: string; message: string }

const enteredQuantities = ref<Record<string, number | ''>>({})
const excludedTransactionIds = ref<string[]>([])
const recordingTransactionId = ref<string | null>(null)
const linkingError = ref<string | null>(null)
const isReadingWallet = ref(false)
const isConfirmingRecordAll = ref(false)
const isRecordingAll = ref(false)
const recordAllFailures = ref<RecordAllFailure[]>([])

function validEnteredQuantity(suggestion: GroupSaleSuggestion): number | null {
  const enteredQuantity = enteredQuantities.value[suggestion.transactionId]
  if (typeof enteredQuantity !== 'number') return null
  return Number.isInteger(enteredQuantity) && enteredQuantity >= 1 && enteredQuantity <= suggestion.quantity ? enteredQuantity : null
}

function hasInvalidEnteredQuantity(suggestion: GroupSaleSuggestion): boolean {
  const enteredQuantity = enteredQuantities.value[suggestion.transactionId]
  return typeof enteredQuantity === 'number' && validEnteredQuantity(suggestion) === null
}

function isPossibleDuplicate(suggestion: GroupSaleSuggestion): boolean {
  return suggestion.possibleDuplicateSaleIds.length > 0
}

function isExcluded(suggestion: GroupSaleSuggestion): boolean {
  return excludedTransactionIds.value.includes(suggestion.transactionId)
}

function isIncluded(suggestion: GroupSaleSuggestion): boolean {
  return !isPossibleDuplicate(suggestion) && !isExcluded(suggestion)
}

function setIncluded(suggestion: GroupSaleSuggestion, included: boolean): void {
  const others = excludedTransactionIds.value.filter(transactionId => transactionId !== suggestion.transactionId)
  excludedTransactionIds.value = included ? others : [...others, suggestion.transactionId]
}

const showInclusionColumn = computed(
  () => store.saleSuggestions.filter(suggestion => !isPossibleDuplicate(suggestion)).length >= MIN_TRANSACTIONS_FOR_RECORD_ALL,
)

const recordAllPlan = computed(() => {
  const recordable = store.saleSuggestions.filter(
    suggestion => isIncluded(suggestion) && !hasInvalidEnteredQuantity(suggestion),
  )
  const selected = [...recordable].sort(
    (first, second) => Date.parse(first.date) - Date.parse(second.date) || first.transactionId.localeCompare(second.transactionId),
  )
  const quantityOf = (suggestion: GroupSaleSuggestion): number => validEnteredQuantity(suggestion) ?? suggestion.quantity
  const productTotals = new Map<number, { typeId: number, typeName: string, quantity: number, totalPrice: number }>()
  for (const suggestion of selected) {
    const quantity = quantityOf(suggestion)
    const product = productTotals.get(suggestion.typeId) ?? { typeId: suggestion.typeId, typeName: suggestion.typeName, quantity: 0, totalPrice: 0 }
    product.quantity += quantity
    product.totalPrice += quantity * suggestion.unitPrice
    productTotals.set(suggestion.typeId, product)
  }
  return {
    selected,
    products: [...productTotals.values()],
    quantity: selected.reduce((sum, suggestion) => sum + quantityOf(suggestion), 0),
    totalPrice: selected.reduce((sum, suggestion) => sum + quantityOf(suggestion) * suggestion.unitPrice, 0),
    leftOut: store.saleSuggestions.filter(suggestion => !isExcluded(suggestion)).length - selected.length,
  }
})
const canRecordAll = computed(() => recordAllPlan.value.selected.length >= MIN_TRANSACTIONS_FOR_RECORD_ALL)

watch(canRecordAll, canRecord => {
  if (!canRecord) isConfirmingRecordAll.value = false
})

function duplicatedSalesOf(suggestion: GroupSaleSuggestion): GroupSale[] {
  return store.sales.filter(sale => suggestion.possibleDuplicateSaleIds.includes(sale.id))
}

function recordingInputOf(suggestion: GroupSaleSuggestion): RecordSaleFromWalletTransactionInput {
  const input: RecordSaleFromWalletTransactionInput = { transactionId: suggestion.transactionId }
  const enteredQuantity = enteredQuantities.value[suggestion.transactionId]
  if (typeof enteredQuantity === 'number') input.quantity = enteredQuantity
  return input
}

async function recordAsSale(suggestion: GroupSaleSuggestion): Promise<void> {
  const input = recordingInputOf(suggestion)

  recordingTransactionId.value = suggestion.transactionId
  linkingError.value = null
  recordAllFailures.value = []
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

async function recordAllPlanned(): Promise<void> {
  const toRecord = [...recordAllPlan.value.selected]
  isConfirmingRecordAll.value = false
  isRecordingAll.value = true
  linkingError.value = null
  recordAllFailures.value = []
  try {
    const { failures } = await store.recordSalesFromWalletTransactions(props.projectId, toRecord.map(recordingInputOf))
    for (const failure of failures) {
      const suggestion = toRecord.find(candidate => candidate.transactionId === failure.transactionId)
      if (suggestion === undefined) {
        linkingError.value = failure.message
        continue
      }
      recordAllFailures.value = [...recordAllFailures.value, {
        transactionId: suggestion.transactionId,
        date: suggestion.date,
        sellerCharacterName: suggestion.sellerCharacterName,
        message: failure.message,
      }]
    }
  } catch (e) {
    linkingError.value = e instanceof Error ? e.message : t('groupIndustry.saleSuggestions.recordFailed')
  } finally {
    isRecordingAll.value = false
  }
  await store.fetchSaleSuggestions(props.projectId)
}

watch(() => syncStore.walletTransactionsProgress, async progress => {
  if (progress === null) return
  if (progress.status === 'started') {
    isReadingWallet.value = true
    return
  }
  isReadingWallet.value = false
  if (progress.status === 'completed') {
    await store.fetchSaleSuggestions(props.projectId)
  }
})

onMounted(() => {
  store.fetchSaleSuggestions(props.projectId)
})
</script>

<template>
  <div class="mb-8">
    <h2 class="text-lg font-semibold text-slate-100 mb-4">{{ t('groupIndustry.saleSuggestions.title') }}</h2>

    <div v-if="!store.loadingSaleSuggestions && store.saleSuggestionsError === null" class="mb-4 space-y-2">
      <WalletReadingChips :readings="store.saleWalletReadings" :empty-hint="store.saleSuggestions.length === 0" />
      <p v-if="isReadingWallet" data-testid="wallet-reading-in-progress" class="text-xs text-cyan-400">{{ t('groupIndustry.walletReading.inProgress') }}</p>
    </div>

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

    <template v-else>
    <div v-if="canRecordAll" class="mb-3 space-y-2">
      <button
        data-testid="sales-record-all"
        class="px-3 py-1 rounded-sm text-xs bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 hover:bg-cyan-500/20 disabled:opacity-50"
        :disabled="recordingDisabled || recordingTransactionId !== null || isRecordingAll"
        @click="isConfirmingRecordAll = true"
      >{{ t('groupIndustry.saleSuggestions.recordAll') }} ({{ recordAllPlan.selected.length }})</button>
      <div
        v-if="isConfirmingRecordAll"
        data-testid="sales-record-all-confirmation"
        class="px-3 py-3 bg-cyan-500/5 border border-cyan-500/30 rounded-lg space-y-2"
      >
        <p class="text-sm text-slate-200">
          <span data-testid="sales-record-all-count">{{ t('groupIndustry.saleSuggestions.recordAllCount', { count: recordAllPlan.selected.length }) }}</span>
          &middot; <span data-testid="sales-record-all-quantity">{{ t('groupIndustry.saleSuggestions.recordAllQuantity', { quantity: formatNumber(recordAllPlan.quantity, 0) }) }}</span>
          &middot; <span data-testid="sales-record-all-total" class="font-mono">{{ t('groupIndustry.saleSuggestions.recordAllTotal', { amount: formatIsk(recordAllPlan.totalPrice, 1) }) }}</span>
        </p>
        <ul data-testid="sales-record-all-products" class="space-y-0.5">
          <li
            v-for="product in recordAllPlan.products"
            :key="product.typeId"
            :data-testid="`sales-record-all-product-${product.typeId}`"
            class="text-xs text-slate-300"
          >{{ t('groupIndustry.saleSuggestions.recordAllProduct', { name: product.typeName, quantity: formatNumber(product.quantity, 0), amount: formatIsk(product.totalPrice, 1) }) }}</li>
        </ul>
        <p
          v-if="recordAllPlan.leftOut > 0"
          data-testid="sales-record-all-left-out"
          class="text-xs text-amber-400"
        >{{ t('groupIndustry.saleSuggestions.recordAllLeftOut', { count: recordAllPlan.leftOut }, recordAllPlan.leftOut) }}</p>
        <div class="flex gap-2">
          <button
            data-testid="sales-record-all-confirm"
            class="px-3 py-1 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-xs font-medium transition-colors disabled:opacity-50"
            :disabled="recordingDisabled || recordingTransactionId !== null || isRecordingAll"
            @click="recordAllPlanned"
          >{{ t('groupIndustry.saleSuggestions.recordAllConfirm') }}</button>
          <button
            data-testid="sales-record-all-cancel"
            class="px-3 py-1 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-xs transition-colors"
            @click="isConfirmingRecordAll = false"
          >{{ t('common.actions.cancel') }}</button>
        </div>
      </div>
    </div>
    <div class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
      <DataTable>
        <table class="w-full text-sm">
          <thead>
            <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-800">
              <th v-if="showInclusionColumn" class="py-3 pl-6 pr-0 w-8"><span class="sr-only">{{ t('groupIndustry.saleSuggestions.includeInRecordAll') }}</span></th>
              <th class="text-left py-3 px-6">{{ t('groupIndustry.saleSuggestions.date') }}</th>
              <th class="text-left py-3 px-3">{{ t('groupIndustry.saleSuggestions.item') }}</th>
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
              <td v-if="showInclusionColumn" class="py-3 pl-6 pr-0">
                <input
                  type="checkbox"
                  :checked="isIncluded(suggestion)"
                  :disabled="isPossibleDuplicate(suggestion)"
                  :aria-label="t('groupIndustry.saleSuggestions.includeInRecordAll')"
                  class="accent-cyan-500"
                  @change="setIncluded(suggestion, ($event.target as HTMLInputElement).checked)"
                />
              </td>
              <td class="py-3 px-6 text-slate-300">{{ formatDate(suggestion.date) }}</td>
              <td class="py-3 px-3 text-slate-200">{{ suggestion.typeName }}</td>
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
                    :disabled="recordingDisabled || recordingTransactionId !== null || isRecordingAll"
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
    </template>

    <div
      v-if="recordAllFailures.length > 0"
      data-testid="sales-record-all-failures"
      class="mt-4 bg-red-500/10 border border-red-500/20 rounded-xl p-4 space-y-1"
    >
      <p class="text-sm text-red-400 font-medium">{{ t('groupIndustry.saleSuggestions.recordAllFailures', { count: recordAllFailures.length }, recordAllFailures.length) }}</p>
      <p
        v-for="failure in recordAllFailures"
        :key="failure.transactionId"
        data-testid="sales-record-all-failure"
        class="text-sm text-red-400"
      >{{ t('groupIndustry.saleSuggestions.recordAllFailure', { date: formatDate(failure.date), character: failure.sellerCharacterName, message: failure.message }) }}</p>
    </div>

    <div v-if="linkingError" class="mt-4 bg-red-500/10 border border-red-500/20 rounded-xl p-4">
      <p class="text-sm text-red-400">{{ linkingError }}</p>
    </div>
  </div>
</template>
