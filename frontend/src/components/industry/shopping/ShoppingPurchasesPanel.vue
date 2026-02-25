<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { PurchaseSuggestion } from '@/stores/industry/types'

type FlatPurchase = {
  id: string
  stepId: string
  stepName: string
  typeName: string
  quantity: number
  unitPrice: number
  totalPrice: number
  source: string
  transactionId: string | null
}

const PURCHASES_PER_PAGE = 15

const props = defineProps<{
  suggestions: PurchaseSuggestion[]
  projectPurchases: FlatPurchase[]
  projectPurchasesTotalCost: number
  materialCost: number | null
  purchasesLoading: boolean
  canFindStepForType: (typeId: number) => boolean
}>()

const emit = defineEmits<{
  loadSuggestions: []
  linkSuggestion: [suggestion: { transactionUuid: string; typeId: number; transactionId: number }]
  removePurchase: [stepId: string, purchaseId: string]
}>()

const { t } = useI18n()
const { formatIsk, formatDateTime } = useFormatters()

const linkingId = ref<number | null>(null)
const suggestionsPage = ref(1)
const purchasesPage = ref(1)

const paginatedSuggestions = computed(() => {
  const start = (suggestionsPage.value - 1) * PURCHASES_PER_PAGE
  return props.suggestions.slice(start, start + PURCHASES_PER_PAGE)
})

const totalSuggestionsPages = computed(() =>
  Math.ceil(props.suggestions.length / PURCHASES_PER_PAGE),
)

const paginatedPurchases = computed(() => {
  const start = (purchasesPage.value - 1) * PURCHASES_PER_PAGE
  return props.projectPurchases.slice(start, start + PURCHASES_PER_PAGE)
})

const totalPurchasesPages = computed(() =>
  Math.ceil(props.projectPurchases.length / PURCHASES_PER_PAGE),
)

function handleLinkSuggestion(suggestion: PurchaseSuggestion) {
  linkingId.value = suggestion.transactionId
  emit('linkSuggestion', {
    transactionUuid: suggestion.transactionUuid,
    typeId: suggestion.typeId,
    transactionId: suggestion.transactionId,
  })
}

function resetLinkingId() {
  linkingId.value = null
}

defineExpose({ resetLinkingId })
</script>

<template>
  <!-- Loading purchases -->
  <div v-if="purchasesLoading" class="text-center py-6 text-slate-500">
    <svg class="w-5 h-5 animate-spin mx-auto mb-2 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
    </svg>
    {{ t('industry.shoppingTab.loadingSuggestions') }}
  </div>

  <template v-else>
    <!-- Purchase suggestions from wallet -->
    <div class="bg-slate-800/50 rounded-xl border border-slate-700 p-4">
      <div class="flex items-center justify-between mb-3">
        <h4 class="text-sm font-semibold text-slate-200">
          {{ t('industry.shoppingTab.purchaseSuggestions') }}
          <span v-if="suggestions.length > 0" class="text-slate-500 font-normal">({{ suggestions.length }})</span>
        </h4>
        <button
          @click="emit('loadSuggestions')"
          class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 border border-slate-600 rounded-lg text-slate-300 text-xs flex items-center gap-1.5"
        >
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          {{ t('common.actions.refresh') }}
        </button>
      </div>

      <div v-if="suggestions.length === 0" class="text-center py-6 text-slate-500 text-sm">
        {{ t('industry.shoppingTab.noWalletMatch') }}
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase">
              <th class="text-left py-2 px-3">{{ t('industry.shoppingTab.date') }}</th>
              <th class="text-left py-2 px-3">{{ t('industry.shoppingTab.item') }}</th>
              <th class="text-right py-2 px-3">{{ t('industry.shoppingTab.qty') }}</th>
              <th class="text-right py-2 px-3">{{ t('industry.shoppingTab.unitPrice') }}</th>
              <th class="text-right py-2 px-3">{{ t('industry.shoppingTab.total') }}</th>
              <th class="text-left py-2 px-3">{{ t('industry.shoppingTab.character') }}</th>
              <th class="text-center py-2 px-3">{{ t('industry.shoppingTab.action') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="suggestion in paginatedSuggestions"
              :key="suggestion.transactionId"
              class="border-b border-slate-800/50 hover:bg-slate-800/30"
              :class="{ 'opacity-50': suggestion.alreadyLinked }"
            >
              <td class="py-2 px-3 text-slate-400 text-xs">{{ formatDateTime(suggestion.date) }}</td>
              <td class="py-2 px-3 text-slate-200">{{ suggestion.typeName }}</td>
              <td class="py-2 px-3 text-right font-mono text-slate-300">{{ suggestion.quantity.toLocaleString() }}</td>
              <td class="py-2 px-3 text-right font-mono text-slate-400">{{ formatIsk(suggestion.unitPrice) }}</td>
              <td class="py-2 px-3 text-right font-mono text-slate-200">{{ formatIsk(suggestion.totalPrice) }}</td>
              <td class="py-2 px-3 text-slate-400 text-xs">{{ suggestion.characterName }}</td>
              <td class="py-2 px-3 text-center">
                <span v-if="suggestion.alreadyLinked" class="px-2 py-0.5 rounded-sm text-xs bg-emerald-500/10 text-emerald-400">{{ t('industry.shoppingTab.linked') }}</span>
                <button
                  v-else-if="canFindStepForType(suggestion.typeId)"
                  @click="handleLinkSuggestion(suggestion)"
                  :disabled="linkingId === suggestion.transactionId"
                  class="px-2 py-0.5 rounded-sm text-xs bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 hover:bg-cyan-500/20 disabled:opacity-50"
                >
                  <template v-if="linkingId === suggestion.transactionId">
                    <svg class="w-3 h-3 animate-spin inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                  </template>
                  <template v-else>{{ t('industry.shoppingTab.link') }}</template>
                </button>
                <span v-else class="text-xs text-slate-600">-</span>
              </td>
            </tr>
          </tbody>
        </table>
        <!-- Suggestions pagination -->
        <div v-if="totalSuggestionsPages > 1" class="flex items-center justify-between px-3 py-2 border-t border-slate-700">
          <span class="text-xs text-slate-500">{{ suggestions.length }} {{ t('industry.shoppingTab.results') }}</span>
          <div class="flex items-center gap-2">
            <button
              @click="suggestionsPage--"
              :disabled="suggestionsPage <= 1"
              class="px-2 py-1 rounded-sm text-xs bg-slate-700 text-slate-300 hover:bg-slate-600 disabled:opacity-40 disabled:cursor-not-allowed"
            >
              {{ t('industry.shoppingTab.previous') }}
            </button>
            <span class="text-xs text-slate-400">{{ suggestionsPage }} / {{ totalSuggestionsPages }}</span>
            <button
              @click="suggestionsPage++"
              :disabled="suggestionsPage >= totalSuggestionsPages"
              class="px-2 py-1 rounded-sm text-xs bg-slate-700 text-slate-300 hover:bg-slate-600 disabled:opacity-40 disabled:cursor-not-allowed"
            >
              {{ t('industry.shoppingTab.next') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Linked purchases for this project -->
    <div class="bg-slate-800/50 rounded-xl border border-slate-700 p-4">
      <h4 class="text-sm font-semibold text-slate-200 mb-3">
        {{ t('industry.shoppingTab.linkedPurchases') }}
        <span v-if="projectPurchases.length > 0" class="text-slate-500 font-normal">({{ projectPurchases.length }})</span>
      </h4>

      <div v-if="projectPurchases.length === 0" class="text-center py-4 text-slate-500 text-sm">
        {{ t('industry.shoppingTab.noLinkedPurchases') }}
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase">
              <th class="text-left py-2 px-3">{{ t('industry.shoppingTab.step') }}</th>
              <th class="text-left py-2 px-3">{{ t('industry.shoppingTab.item') }}</th>
              <th class="text-right py-2 px-3">{{ t('industry.shoppingTab.qty') }}</th>
              <th class="text-right py-2 px-3">{{ t('industry.shoppingTab.unitPrice') }}</th>
              <th class="text-right py-2 px-3">{{ t('industry.shoppingTab.total') }}</th>
              <th class="text-center py-2 px-3">{{ t('industry.shoppingTab.source') }}</th>
              <th class="text-center py-2 px-3">{{ t('industry.shoppingTab.action') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="purchase in paginatedPurchases"
              :key="purchase.id"
              class="border-b border-slate-800/50 hover:bg-slate-800/30"
            >
              <td class="py-2 px-3 text-slate-400 text-xs">{{ purchase.stepName }}</td>
              <td class="py-2 px-3 text-slate-200">{{ purchase.typeName }}</td>
              <td class="py-2 px-3 text-right font-mono text-slate-300">{{ purchase.quantity.toLocaleString() }}</td>
              <td class="py-2 px-3 text-right font-mono text-slate-400">{{ formatIsk(purchase.unitPrice) }}</td>
              <td class="py-2 px-3 text-right font-mono text-slate-200">{{ formatIsk(purchase.totalPrice) }}</td>
              <td class="py-2 px-3 text-center">
                <span
                  :class="[
                    'px-2 py-0.5 rounded-sm text-xs',
                    purchase.source === 'esi_wallet' ? 'bg-cyan-500/10 text-cyan-400' : 'bg-amber-500/10 text-amber-400'
                  ]"
                >
                  {{ purchase.source === 'esi_wallet' ? 'ESI' : t('industry.shoppingTab.manual') }}
                </span>
              </td>
              <td class="py-2 px-3 text-center">
                <button
                  @click="emit('removePurchase', purchase.stepId, purchase.id)"
                  class="text-slate-500 hover:text-red-400"
                  :title="t('industry.shoppingTab.unlink')"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <!-- Purchases pagination -->
        <div v-if="totalPurchasesPages > 1" class="flex items-center justify-between px-3 py-2 border-t border-slate-700">
          <span class="text-xs text-slate-500">{{ projectPurchases.length }} {{ t('industry.shoppingTab.results') }}</span>
          <div class="flex items-center gap-2">
            <button
              @click="purchasesPage--"
              :disabled="purchasesPage <= 1"
              class="px-2 py-1 rounded-sm text-xs bg-slate-700 text-slate-300 hover:bg-slate-600 disabled:opacity-40 disabled:cursor-not-allowed"
            >
              {{ t('industry.shoppingTab.previous') }}
            </button>
            <span class="text-xs text-slate-400">{{ purchasesPage }} / {{ totalPurchasesPages }}</span>
            <button
              @click="purchasesPage++"
              :disabled="purchasesPage >= totalPurchasesPages"
              class="px-2 py-1 rounded-sm text-xs bg-slate-700 text-slate-300 hover:bg-slate-600 disabled:opacity-40 disabled:cursor-not-allowed"
            >
              {{ t('industry.shoppingTab.next') }}
            </button>
          </div>
        </div>

        <!-- Cost comparison -->
        <div v-if="projectPurchasesTotalCost > 0" class="mt-4 pt-3 border-t border-slate-700 flex items-center gap-6 text-sm">
          <span class="text-slate-400">{{ t('industry.shoppingTab.actualPurchaseCost') }}:</span>
          <span class="font-mono text-slate-200">{{ formatIsk(projectPurchasesTotalCost) }}</span>
          <template v-if="materialCost">
            <span class="text-slate-400">{{ t('industry.shoppingTab.estimated') }}:</span>
            <span class="font-mono text-slate-400">{{ formatIsk(materialCost) }}</span>
            <span
              :class="[
                'font-mono',
                projectPurchasesTotalCost <= materialCost ? 'text-emerald-400' : 'text-red-400'
              ]"
            >
              {{ projectPurchasesTotalCost <= materialCost ? '-' : '+' }}{{ formatIsk(Math.abs(projectPurchasesTotalCost - materialCost)) }}
            </span>
          </template>
        </div>
      </div>
    </div>
  </template>
</template>
