<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useSyncStore } from '@/stores/sync'
import { useFormatters } from '@/composables/useFormatters'
import BaseModal from '@/components/common/BaseModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import WalletReadingChips from '@/components/group-industry/WalletReadingChips.vue'
import type { BomItem, PurchaseSuggestion, WalletReading } from '@/stores/group-industry/types'

const props = defineProps<{
  item: BomItem
}>()

const { t } = useI18n()
const projectStore = useGroupProjectStore()
const syncStore = useSyncStore()
const { formatIsk, formatDateTime, formatNumber } = useFormatters()

const isProjectOwner = computed(() => projectStore.currentProject?.myRole === 'owner')
const isOpen = ref(false)
const isLoadingSuggestions = ref(false)
const suggestions = ref<PurchaseSuggestion[]>([])
const walletReadings = ref<WalletReading[]>([])
const isReadingWallet = ref(false)
const newTransactionIds = ref<Set<number>>(new Set())
const purchaseError = ref<string | null>(null)
const isSubmitting = ref(false)
const manualQuantity = ref<number | null>(null)
const manualUnitPrice = ref<number | null>(null)

const lineSuggestions = computed(() => suggestions.value.filter(suggestion => suggestion.typeId === props.item.typeId))
const canSubmitManualPurchase = computed(() =>
  !isSubmitting.value
  && manualQuantity.value !== null && Number.isInteger(manualQuantity.value) && manualQuantity.value > 0
  && manualUnitPrice.value !== null && manualUnitPrice.value >= 0,
)

function failureMessage(error: unknown, fallbackKey: string): string {
  return error instanceof Error ? error.message : t(fallbackKey)
}

function transactionsNewSince(previous: PurchaseSuggestion[], current: PurchaseSuggestion[]): Set<number> {
  const previousById = new Map(previous.map(suggestion => [suggestion.transactionId, suggestion]))
  const appeared = current.filter(suggestion => {
    const before = previousById.get(suggestion.transactionId)
    return before === undefined || (!before.alreadyLinked && suggestion.alreadyLinked)
  })
  return new Set(appeared.map(suggestion => suggestion.transactionId))
}

async function loadSuggestions(projectId: string, isReload: boolean): Promise<void> {
  try {
    const loaded = await projectStore.fetchPurchaseSuggestions(projectId)
    if (!isOpen.value) return
    if (isReload) {
      const appeared = transactionsNewSince(suggestions.value, loaded.suggestions)
      newTransactionIds.value = new Set([...newTransactionIds.value, ...appeared])
    }
    suggestions.value = loaded.suggestions
    walletReadings.value = loaded.walletReadings
  } catch (e) {
    suggestions.value = []
    walletReadings.value = []
    purchaseError.value = failureMessage(e, 'groupIndustry.purchases.loadError')
  }
}

async function openPanel(): Promise<void> {
  const project = projectStore.currentProject
  if (project === null) return
  isOpen.value = true
  isReadingWallet.value = false
  newTransactionIds.value = new Set()
  purchaseError.value = null
  isLoadingSuggestions.value = true
  await loadSuggestions(project.id, false)
  isLoadingSuggestions.value = false
}

function closePanel(): void {
  isOpen.value = false
  isReadingWallet.value = false
}

watch(() => syncStore.walletTransactionsProgress, async progress => {
  if (!isOpen.value || progress === null) return
  if (progress.status === 'started') {
    isReadingWallet.value = true
    return
  }
  isReadingWallet.value = false
  const project = projectStore.currentProject
  if (progress.status === 'completed' && project !== null) {
    await loadSuggestions(project.id, true)
  }
})

async function submitPurchase(
  submit: (projectId: string) => Promise<void>,
  fallbackKey: string,
): Promise<boolean> {
  const project = projectStore.currentProject
  if (project === null) return false
  isSubmitting.value = true
  purchaseError.value = null
  try {
    await submit(project.id)
    return true
  } catch (e) {
    purchaseError.value = failureMessage(e, fallbackKey)
    return false
  } finally {
    isSubmitting.value = false
  }
}

async function linkSuggestion(suggestion: PurchaseSuggestion): Promise<void> {
  const isLinked = await submitPurchase(
    projectId => projectStore.createPurchase(projectId, props.item.id, { transactionId: suggestion.transactionUuid }),
    'groupIndustry.purchases.linkError',
  )
  if (isLinked) {
    suggestions.value = suggestions.value.map(candidate =>
      candidate.transactionId === suggestion.transactionId ? { ...candidate, alreadyLinked: true } : candidate,
    )
  }
}

async function addManualPurchase(): Promise<void> {
  if (manualQuantity.value === null || manualUnitPrice.value === null) return
  const input = { typeId: props.item.typeId, quantity: manualQuantity.value, unitPrice: manualUnitPrice.value }
  const isAdded = await submitPurchase(
    projectId => projectStore.createPurchase(projectId, props.item.id, input),
    'groupIndustry.purchases.addError',
  )
  if (isAdded) {
    manualQuantity.value = null
    manualUnitPrice.value = null
  }
}

async function deletePurchase(purchaseId: string): Promise<void> {
  await submitPurchase(
    projectId => projectStore.deletePurchase(projectId, purchaseId),
    'groupIndustry.purchases.deleteError',
  )
}
</script>

<template>
  <div v-if="item.purchases.length > 0 || isProjectOwner" class="mt-1">
    <ul v-if="item.purchases.length > 0" class="space-y-0.5">
      <li
        v-for="purchase in item.purchases"
        :key="purchase.id"
        data-testid="purchase"
        class="text-xs text-slate-500 flex items-center gap-2"
      >
        <span>
          {{ formatNumber(purchase.quantity, 0) }} &times; {{ formatIsk(purchase.unitPrice, 1) }}
          &middot; {{ purchase.transactionId !== null ? t('groupIndustry.purchases.sourceTransaction') : t('groupIndustry.purchases.sourceManual') }}
        </span>
        <button
          v-if="isProjectOwner"
          data-testid="purchase-delete"
          class="text-slate-500 hover:text-red-400 disabled:opacity-50"
          :disabled="isSubmitting"
          :aria-label="t('common.actions.delete')"
          :title="t('common.actions.delete')"
          @click="deletePurchase(purchase.id)"
        >
          <svg aria-hidden="true" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </li>
    </ul>

    <p
      v-if="purchaseError !== null && !isOpen"
      data-testid="purchase-error"
      role="alert"
      class="mt-1 text-xs text-red-400"
    >{{ purchaseError }}</p>

    <button
      v-if="isProjectOwner"
      data-testid="purchases-open"
      class="mt-1 px-2 py-0.5 rounded-sm text-xs bg-slate-700 hover:bg-slate-600 text-slate-200 transition-colors"
      @click="openPanel"
    >{{ t('groupIndustry.purchases.open') }}</button>

    <BaseModal
      v-if="isProjectOwner"
      :show="isOpen"
      :title="t('groupIndustry.purchases.modalTitle', { name: item.typeName })"
      panel-class="border-slate-700 max-w-3xl max-h-[90vh] overflow-y-auto"
      @close="closePanel"
    >
      <template #title="{ titleId }">
        <div class="px-6 py-5 border-b border-slate-800 flex items-center justify-between">
          <h2 :id="titleId" class="text-lg font-bold text-slate-100">{{ t('groupIndustry.purchases.modalTitle', { name: item.typeName }) }}</h2>
          <button
            class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 flex items-center justify-center text-slate-400 hover:text-slate-200 transition-colors"
            :aria-label="t('common.actions.close')"
            @click="closePanel"
          >
            <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </template>

      <div class="px-6 py-5 space-y-5">
        <p
          v-if="purchaseError !== null"
          data-testid="purchase-error"
          role="alert"
          class="px-3 py-2 bg-red-500/10 border border-red-500/30 rounded-lg text-red-400 text-sm"
        >{{ purchaseError }}</p>

        <section>
          <h3 class="text-sm font-semibold text-slate-200 mb-3">{{ t('groupIndustry.purchases.suggestionsTitle') }}</h3>
          <div class="mb-3 space-y-2">
            <WalletReadingChips :readings="walletReadings" :empty-hint="!isLoadingSuggestions && purchaseError === null && lineSuggestions.length === 0" />
            <p v-if="isReadingWallet" data-testid="wallet-reading-in-progress" class="text-xs text-cyan-400">{{ t('groupIndustry.walletReading.inProgress') }}</p>
          </div>
          <div v-if="isLoadingSuggestions" class="py-6 text-center text-slate-500 text-sm">
            <LoadingSpinner class="w-5 h-5 mx-auto mb-2 text-cyan-500" />
            {{ t('groupIndustry.purchases.suggestionsLoading') }}
          </div>
          <p v-else-if="lineSuggestions.length === 0" class="py-4 text-center text-slate-500 text-sm">
            {{ t('groupIndustry.purchases.suggestionsEmpty') }}
          </p>
          <div v-else class="bg-slate-800/50 rounded-xl border border-slate-700/50 overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-700">
                  <th class="text-left py-2.5 px-4">{{ t('groupIndustry.purchases.date') }}</th>
                  <th class="text-left py-2.5 px-3">{{ t('groupIndustry.jobs.character') }}</th>
                  <th class="text-right py-2.5 px-3">{{ t('groupIndustry.purchases.quantity') }}</th>
                  <th class="text-right py-2.5 px-3">{{ t('groupIndustry.purchases.unitPrice') }}</th>
                  <th class="text-right py-2.5 px-3">{{ t('groupIndustry.purchases.totalPrice') }}</th>
                  <th class="text-right py-2.5 px-4"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-700/50">
                <tr v-for="suggestion in lineSuggestions" :key="suggestion.transactionId" data-testid="purchase-suggestion" class="hover:bg-slate-800/30">
                  <td class="py-2.5 px-4 text-xs text-slate-400">{{ formatDateTime(suggestion.date) }}</td>
                  <td class="py-2.5 px-3 text-slate-200">
                    {{ suggestion.characterName }}
                    <span
                      v-if="newTransactionIds.has(suggestion.transactionId)"
                      data-testid="purchase-suggestion-new"
                      class="ml-2 px-1.5 py-0.5 rounded-sm text-xs bg-cyan-500/10 text-cyan-300 border border-cyan-500/30"
                    >{{ t('groupIndustry.walletReading.newTransaction') }}</span>
                  </td>
                  <td class="py-2.5 px-3 text-right font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">{{ formatNumber(suggestion.quantity, 0) }}</td>
                  <td class="py-2.5 px-3 text-right font-mono text-slate-400" style="font-variant-numeric: tabular-nums;">{{ formatIsk(suggestion.unitPrice, 1) }}</td>
                  <td class="py-2.5 px-3 text-right font-mono text-slate-200" style="font-variant-numeric: tabular-nums;">{{ formatIsk(suggestion.totalPrice, 1) }}</td>
                  <td class="py-2.5 px-4 text-right">
                    <span
                      v-if="suggestion.alreadyLinked"
                      data-testid="purchase-suggestion-linked"
                      class="px-2 py-0.5 rounded-sm text-xs bg-emerald-500/10 text-emerald-400"
                    >{{ t('groupIndustry.purchases.alreadyLinked') }}</span>
                    <button
                      v-else
                      data-testid="purchase-suggestion-link"
                      class="px-2 py-0.5 rounded-sm text-xs bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 hover:bg-cyan-500/20 disabled:opacity-50"
                      :disabled="isSubmitting"
                      @click="linkSuggestion(suggestion)"
                    >{{ t('groupIndustry.purchases.link') }}</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section>
          <h3 class="text-sm font-semibold text-slate-200 mb-3">{{ t('groupIndustry.purchases.manualTitle') }}</h3>
          <div class="flex items-end gap-3">
            <label class="block text-xs text-slate-500">
              {{ t('groupIndustry.purchases.quantity') }}
              <input
                v-model.number="manualQuantity"
                data-testid="manual-purchase-quantity"
                type="number"
                min="1"
                step="1"
                class="mt-1 block w-32 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 font-mono focus:outline-hidden focus:border-cyan-500"
              />
            </label>
            <label class="block text-xs text-slate-500">
              {{ t('groupIndustry.purchases.unitPrice') }}
              <input
                v-model.number="manualUnitPrice"
                data-testid="manual-purchase-unit-price"
                type="number"
                min="0"
                step="any"
                class="mt-1 block w-40 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 font-mono focus:outline-hidden focus:border-cyan-500"
              />
            </label>
            <button
              data-testid="manual-purchase-submit"
              class="px-3 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium transition-colors disabled:opacity-50"
              :disabled="!canSubmitManualPurchase"
              @click="addManualPurchase"
            >{{ t('groupIndustry.purchases.add') }}</button>
          </div>
        </section>
      </div>
    </BaseModal>
  </div>
</template>
