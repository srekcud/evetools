<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import BaseModal from '@/components/common/BaseModal.vue'
import LootSaleActions from '@/components/ledger/pve/LootSaleActions.vue'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import type { CorporationProjectError, LootSaleScanResult, DetectedLootSale } from '@/types/pve'

defineProps<{
  visible: boolean
  scanResults: LootSaleScanResult | null
  isImporting: boolean
}>()

const emit = defineEmits<{
  close: []
  importSale: [sale: DetectedLootSale]
  ignoreSale: [sale: DetectedLootSale]
  openLootConfig: []
}>()

const REASONS_REQUIRING_RECONNECTION = ['no_valid_character', 'missing_projects_scope']

const { t } = useI18n()

function hasAuthenticationNotice(results: LootSaleScanResult): boolean {
  return results.charactersToReconnect.length > 0
    || results.corporationProjectErrors.some(error => REASONS_REQUIRING_RECONNECTION.includes(error.reason))
}

function hasNotice(results: LootSaleScanResult): boolean {
  return hasAuthenticationNotice(results) || results.corporationProjectErrors.length > 0 || results.noLootTypesConfigured
}

function summaryOf(results: LootSaleScanResult): string {
  const counts = { transactions: results.scannedTransactions, contracts: results.scannedContracts, projects: results.scannedProjects, detected: results.detectedSales.length }
  if (results.noLootTypesConfigured) {
    return t(results.scannedProjects === null ? 'pve.lootScanSummaryNoLootTypesUnknownProjects' : 'pve.lootScanSummaryNoLootTypes', counts)
  }
  return t(results.scannedProjects === null ? 'pve.lootScanSummaryUnknownProjects' : 'pve.lootScanSummary', counts)
}

function describeCorporationProjectError(error: CorporationProjectError): string {
  const subject = error.projectName === null
    ? t('pve.corpProjectErrors.subject.corporation', { corporation: error.corporationName })
    : error.characterName === null
      ? t('pve.corpProjectErrors.subject.project', { project: error.projectName, corporation: error.corporationName })
      : t('pve.corpProjectErrors.subject.contribution', { character: error.characterName, project: error.projectName })
  const reason = error.reason === 'esi_error' && error.httpStatus === null
    ? t('pve.corpProjectErrors.reason.esi_error_network')
    : t(`pve.corpProjectErrors.reason.${error.reason}`, { status: error.httpStatus })
  return t('pve.corpProjectErrors.line', { subject, reason })
}

const { formatDate } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
</script>

<template>
  <BaseModal
    v-if="scanResults"
    :show="visible"
    :title="t('pve.detectedLootSales')"
    panel-class="border-slate-700 max-w-2xl max-h-[80vh] flex flex-col"
    @close="emit('close')"
  >
    <template #title="{ titleId }">
      <div class="p-6 border-b border-slate-800">
        <div class="flex items-center justify-between">
          <div>
            <h3 :id="titleId" class="text-lg font-semibold">{{ t('pve.detectedLootSales') }}</h3>
            <p class="text-sm text-slate-400 mt-1">
              {{ summaryOf(scanResults) }}
            </p>
          </div>
          <button @click="emit('close')" class="text-slate-400 hover:text-slate-300" :aria-label="t('common.actions.close')">
            <svg aria-hidden="true" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
      </div>
    </template>

    <div v-if="hasNotice(scanResults)" class="px-6 pt-4 space-y-3">
      <div
        v-for="(error, index) in scanResults.corporationProjectErrors"
        :key="index"
        role="alert"
        class="bg-red-500/10 border border-red-500/20 rounded-xl p-4 text-sm text-red-400"
      >
        {{ describeCorporationProjectError(error) }}
      </div>
      <div
        v-if="scanResults.noLootTypesConfigured"
        class="bg-amber-500/10 border border-amber-500/20 rounded-xl p-4 text-sm text-amber-400 space-y-2"
      >
        <p>{{ t('pve.noLootTypesProjectsOnly') }}</p>
        <button
          type="button"
          class="px-3 py-1 text-xs bg-amber-600 hover:bg-amber-500 rounded-sm text-white font-medium"
          @click="emit('openLootConfig')"
        >
          {{ t('pve.configureLootTypes') }}
        </button>
      </div>
      <div
        v-if="scanResults.charactersToReconnect.length > 0"
        class="bg-amber-500/10 border border-amber-500/20 rounded-xl p-4 text-sm text-amber-400"
      >
        {{ t('pve.charactersToReconnect', { count: scanResults.charactersToReconnect.length, names: scanResults.charactersToReconnect.join(', ') }) }}
      </div>
      <RouterLink v-if="hasAuthenticationNotice(scanResults)" to="/characters" class="inline-block text-sm text-blue-400 hover:text-blue-300 underline">
        {{ t('pve.reconnectCharactersLink') }}
      </RouterLink>
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
            <LootSaleActions
              :detected-sale="sale"
              :is-importing="isImporting"
              @import-sale="emit('importSale', sale)"
              @ignore-sale="emit('ignoreSale', sale)"
            />
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
  </BaseModal>
</template>
