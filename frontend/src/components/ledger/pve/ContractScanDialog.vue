<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import type { ContractScanResult, DetectedExpense } from '@/types/pve'

defineProps<{
  visible: boolean
  scanResults: ContractScanResult | null
  isImporting: boolean
  expenseTypes: { value: string; label: string }[]
}>()

const emit = defineEmits<{
  close: []
  import: []
  toggleSelection: [expense: DetectedExpense]
  selectAll: []
  deselectAll: []
}>()

const { t } = useI18n()
const { formatIskFull, formatDate } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

function getExpenseTypeLabel(type: string, types: { value: string; label: string }[]): string {
  return types.find(et => et.value === type)?.label || type
}

function getExpenseTypeColor(type: string): string {
  switch (type) {
    case 'fuel': return 'bg-orange-500/20 text-orange-400'
    case 'ammo': return 'bg-red-500/20 text-red-400'
    case 'crab_beacon': return 'bg-purple-500/20 text-purple-400'
    default: return 'bg-slate-500/20 text-slate-400'
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="visible && scanResults" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" @click.self="emit('close')">
      <div class="bg-slate-900 rounded-xl border border-slate-700 max-w-2xl w-full max-h-[80vh] flex flex-col">
        <div class="p-6 border-b border-slate-800">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-lg font-semibold">{{ t('pve.scanResults') }}</h3>
              <p class="text-sm text-slate-400 mt-1">
                {{ t('pve.scanResultsSummary', { contracts: scanResults.scannedContracts, transactions: scanResults.scannedTransactions, detected: scanResults.detectedExpenses.length }) }}
              </p>
            </div>
            <button @click="emit('close')" class="text-slate-400 hover:text-slate-300">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>
        </div>

        <div v-if="scanResults.detectedExpenses.length > 0" class="flex-1 overflow-y-auto p-6">
          <!-- Selection controls -->
          <div class="flex items-center gap-3 mb-4">
            <button @click="emit('selectAll')" class="text-sm text-cyan-400 hover:text-cyan-300">{{ t('pve.selectAll') }}</button>
            <span class="text-slate-600">|</span>
            <button @click="emit('deselectAll')" class="text-sm text-slate-400 hover:text-slate-300">{{ t('pve.deselectAll') }}</button>
          </div>

          <!-- Expenses list -->
          <div class="space-y-3">
            <div
              v-for="expense in scanResults.detectedExpenses"
              :key="`${expense.contractId}-${expense.typeId}`"
              @click="emit('toggleSelection', expense)"
              :class="[
                'p-4 rounded-lg border cursor-pointer transition-colors',
                expense.selected
                  ? 'bg-slate-800 border-cyan-500/50'
                  : 'bg-slate-800/50 border-slate-700 opacity-60'
              ]"
            >
              <div class="flex items-start gap-4">
                <div class="shrink-0 pt-1">
                  <div :class="[
                    'w-5 h-5 rounded-sm border-2 flex items-center justify-center',
                    expense.selected ? 'border-cyan-500 bg-cyan-500' : 'border-slate-600'
                  ]">
                    <svg v-if="expense.selected" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                    </svg>
                  </div>
                </div>
                <img
                  :src="getTypeIconUrl(expense.typeId, 64)"
                  :alt="expense.typeName"
                  class="w-12 h-12 rounded-sm"
                  @error="onImageError"
                />
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 mb-1">
                    <span :class="['text-xs px-2 py-0.5 rounded-sm', getExpenseTypeColor(expense.type)]">
                      {{ getExpenseTypeLabel(expense.type, expenseTypes) }}
                    </span>
                    <span v-if="expense.source === 'contract'" class="text-xs text-slate-500">
                      {{ t('pve.contractNumber', { id: expense.contractId }) }}
                    </span>
                    <span v-else class="text-xs text-blue-400">
                      {{ t('pve.market') }}
                    </span>
                  </div>
                  <p class="text-sm text-slate-300">{{ expense.typeName }}</p>
                </div>
                <div class="text-right">
                  <p class="text-red-400 font-mono">{{ formatIskFull(expense.price) }}</p>
                  <p class="text-xs text-slate-500">{{ formatDate(expense.dateIssued) }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-else class="flex-1 flex items-center justify-center p-6">
          <div class="text-center text-slate-500">
            <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M12 12h.01M12 12h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p>{{ t('pve.noExpenseDetected') }}</p>
            <p class="text-sm mt-1">{{ t('pve.checkAmmoConfig') }}</p>
          </div>
        </div>

        <div v-if="scanResults.detectedExpenses.length > 0" class="p-6 border-t border-slate-800">
          <div class="flex items-center justify-between mb-4">
            <span class="text-sm text-slate-400">
              {{ t('pve.selectedExpenses', { count: scanResults.detectedExpenses.filter(e => e.selected).length }) }}
            </span>
            <span class="text-red-400 font-mono">
              Total: {{ formatIskFull(scanResults.detectedExpenses.filter(e => e.selected).reduce((sum, e) => sum + e.price, 0)) }}
            </span>
          </div>
          <div class="flex gap-3">
            <button
              @click="emit('close')"
              class="flex-1 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg"
            >
              {{ t('common.actions.cancel') }}
            </button>
            <button
              @click="emit('import')"
              :disabled="isImporting || scanResults.detectedExpenses.filter(e => e.selected).length === 0"
              class="flex-1 py-2 bg-cyan-600 hover:bg-cyan-500 text-white font-medium rounded-lg disabled:opacity-50 flex items-center justify-center gap-2"
            >
              <svg v-if="isImporting" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
              {{ isImporting ? t('pve.importing') : t('pve.import') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
