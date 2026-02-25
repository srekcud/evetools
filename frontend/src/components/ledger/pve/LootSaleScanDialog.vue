<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import type { LootSaleScanResult, DetectedLootSale } from '@/types/pve'

defineProps<{
  visible: boolean
  scanResults: LootSaleScanResult | null
  isImporting: boolean
}>()

const emit = defineEmits<{
  close: []
  importSale: [sale: DetectedLootSale]
  ignoreSale: [sale: DetectedLootSale]
}>()

const { t } = useI18n()
const { formatIskFull, formatDate } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
</script>

<template>
  <Teleport to="body">
    <div v-if="visible && scanResults" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" @click.self="emit('close')">
      <div class="bg-slate-900 rounded-xl border border-slate-700 max-w-2xl w-full max-h-[80vh] flex flex-col">
        <div class="p-6 border-b border-slate-800">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-lg font-semibold">{{ t('pve.detectedLootSales') }}</h3>
              <p class="text-sm text-slate-400 mt-1">
                {{ t('pve.lootScanSummary', { transactions: scanResults.scannedTransactions, contracts: scanResults.scannedContracts || 0, projects: scanResults.scannedProjects || 0, detected: scanResults.detectedSales.length }) }}
              </p>
            </div>
            <button @click="emit('close')" class="text-slate-400 hover:text-slate-300">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>
        </div>

        <div v-if="scanResults.detectedSales.length > 0" class="flex-1 overflow-y-auto p-6">
          <!-- Sales list -->
          <div class="space-y-3">
            <div
              v-for="sale in scanResults.detectedSales"
              :key="sale.source === 'contract' ? `contract-${sale.contractId}` : `tx-${sale.transactionId}`"
              class="p-4 rounded-lg border bg-slate-800 border-slate-700"
            >
              <div class="flex items-start gap-4">
                <img
                  :src="getTypeIconUrl(sale.typeId, 64)"
                  :alt="sale.typeName"
                  class="w-12 h-12 rounded-sm"
                  @error="onImageError"
                />
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 mb-1">
                    <span v-if="sale.source === 'contract'" class="text-xs px-2 py-0.5 rounded-sm bg-amber-500/20 text-amber-400">
                      {{ t('pve.contractNumber', { id: sale.contractId }) }}
                    </span>
                    <span v-else-if="sale.source === 'corp_project'" class="text-xs px-2 py-0.5 rounded-sm bg-violet-500/20 text-violet-400">
                      {{ sale.projectName || t('pve.corpProject') }}
                    </span>
                    <span v-else class="text-xs px-2 py-0.5 rounded-sm bg-blue-500/20 text-blue-400">
                      {{ t('pve.market') }}
                    </span>
                    <span class="text-xs text-slate-500">{{ sale.characterName }}</span>
                  </div>
                  <p class="text-sm text-slate-300">{{ sale.typeName }}</p>
                  <p class="text-xs text-slate-500">{{ formatDate(sale.dateIssued) }}</p>
                </div>
                <div class="flex flex-col items-end gap-2">
                  <!-- Editable price for 0 ISK contracts or corp project contributions -->
                  <div v-if="(sale.source === 'contract' || sale.source === 'corp_project') && sale.price === 0" class="flex items-center gap-1">
                    <input
                      type="number"
                      v-model.number="sale.price"
                      class="w-28 px-2 py-1 text-right bg-slate-700 border border-slate-600 rounded-sm text-amber-400 font-mono text-sm focus:outline-hidden focus:border-amber-500"
                      min="0"
                      step="100000"
                      :placeholder="sale.source === 'corp_project' ? 'Valeur' : 'Prix'"
                    />
                    <span class="text-xs text-slate-500">ISK</span>
                  </div>
                  <!-- Fixed price display -->
                  <p v-else class="text-amber-400 font-mono text-sm">+{{ formatIskFull(sale.price) }}</p>
                  <!-- Action buttons -->
                  <div class="flex gap-2">
                    <button
                      @click="emit('importSale', sale)"
                      :disabled="isImporting || ((sale.source === 'contract' || sale.source === 'corp_project') && sale.price <= 0)"
                      class="px-3 py-1 text-xs bg-emerald-600 hover:bg-emerald-500 disabled:bg-slate-600 disabled:cursor-not-allowed rounded-sm text-white font-medium"
                    >
                      {{ t('common.actions.add') }}
                    </button>
                    <button
                      @click="emit('ignoreSale', sale)"
                      :disabled="isImporting"
                      class="px-3 py-1 text-xs bg-slate-600 hover:bg-slate-500 disabled:opacity-50 rounded-sm text-slate-300 font-medium"
                    >
                      {{ t('pve.ignore') }}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-else class="flex-1 flex items-center justify-center p-6">
          <div class="text-center text-slate-500">
            <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
            <p>{{ t('pve.noLootSalesDetected') }}</p>
            <p class="text-sm mt-1">{{ t('pve.sellLootHint') }}</p>
          </div>
        </div>

        <div v-if="scanResults.detectedSales.length > 0" class="p-4 border-t border-slate-800">
          <div class="flex items-center justify-between">
            <span class="text-sm text-slate-400">
              {{ t('pve.pendingItems', { count: scanResults.detectedSales.length }) }}
            </span>
            <button
              @click="emit('close')"
              class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-sm"
            >
              {{ t('common.actions.close') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
