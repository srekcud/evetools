<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import type { MergedItem } from '@/components/appraisal/AppraisalResultsTable.vue'

const props = defineProps<{
  mergedItems: MergedItem[]
  hasStructureResults: boolean
  shortStructureName: string
  jitaItemCount: number
  structureItemCount: number
  isSharing: boolean
  shareUrl: string | null
}>()

const emit = defineEmits<{
  'share': []
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()

const copiedJita = ref(false)
const copiedStructure = ref(false)
const copiedTable = ref(false)
const copiedShare = ref(false)

function copyJitaMultibuy(): void {
  const items = props.mergedItems.filter(i => i.bestLocation === 'jita' || i.bestLocation === null)
  const text = items.map(i => `${i.typeName}\t${i.quantity}`).join('\n')
  navigator.clipboard.writeText(text)
  copiedJita.value = true
  setTimeout(() => copiedJita.value = false, 2000)
}

function copyStructureMultibuy(): void {
  const items = props.mergedItems.filter(i => i.bestLocation === 'structure')
  const text = items.map(i => `${i.typeName}\t${i.quantity}`).join('\n')
  navigator.clipboard.writeText(text)
  copiedStructure.value = true
  setTimeout(() => copiedStructure.value = false, 2000)
}

function copyTable(): void {
  const header = ['Item', 'Qty', 'Volume', 'Jita Sell', 'Jita Buy', 'Jita+Import', 'Structure', 'Buy at'].join('\t')
  const rows = props.mergedItems.map(i => [
    i.typeName,
    i.quantity,
    formatNumber(i.totalVolume) + ' m3',
    i.sellTotalWeighted != null ? formatNumber(i.sellTotalWeighted) : '-',
    i.buyTotalWeighted != null ? formatNumber(i.buyTotalWeighted) : '-',
    i.jitaWithImport != null ? formatNumber(i.jitaWithImport) : '-',
    i.structureTotal != null ? formatNumber(i.structureTotal) : '-',
    i.bestLocation ?? '-',
  ].join('\t'))
  const text = [header, ...rows].join('\n')
  navigator.clipboard.writeText(text)
  copiedTable.value = true
  setTimeout(() => copiedTable.value = false, 2000)
}

// Show "copied" feedback when shareUrl becomes non-null (share succeeded)
watch(() => props.shareUrl, (newUrl) => {
  if (newUrl) {
    copiedShare.value = true
    setTimeout(() => copiedShare.value = false, 3000)
  }
})
</script>

<template>
  <div class="flex flex-wrap gap-3 items-center">
    <!-- Copy Jita Multibuy -->
    <button
      v-if="props.hasStructureResults && props.jitaItemCount > 0"
      @click="copyJitaMultibuy"
      class="flex items-center gap-2 px-4 py-2 bg-cyan-500/15 border border-cyan-500/30 rounded-lg text-cyan-300 text-[13px] font-medium cursor-pointer hover:bg-cyan-500/25 transition-colors"
    >
      <svg v-if="!copiedJita" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
      </svg>
      <svg v-else class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
      </svg>
      {{ copiedJita ? t('shopping.copied') : t('shopping.copyJita', { count: props.jitaItemCount }) }}
    </button>

    <!-- Copy Structure Multibuy -->
    <button
      v-if="props.hasStructureResults && props.structureItemCount > 0"
      @click="copyStructureMultibuy"
      class="flex items-center gap-2 px-4 py-2 bg-violet-500/15 border border-violet-500/30 rounded-lg text-violet-300 text-[13px] font-medium cursor-pointer hover:bg-violet-500/25 transition-colors"
    >
      <svg v-if="!copiedStructure" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
      </svg>
      <svg v-else class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
      </svg>
      {{ copiedStructure ? t('shopping.copied') : t('shopping.copyStructure', { name: props.shortStructureName, count: props.structureItemCount }) }}
    </button>

    <!-- Copy Table -->
    <button
      @click="copyTable"
      class="flex items-center gap-2 px-4 py-2 bg-slate-700/50 border border-slate-700 rounded-lg text-slate-400 text-[11px] font-medium cursor-pointer hover:text-slate-200 hover:bg-slate-700 transition-colors"
    >
      <svg v-if="!copiedTable" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
      </svg>
      <svg v-else class="w-3.5 h-3.5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
      </svg>
      {{ copiedTable ? t('shopping.copied') : t('appraisal.copyTable') }}
    </button>

    <div class="flex-1"></div>

    <!-- Share -->
    <button
      @click="emit('share')"
      :disabled="props.isSharing"
      class="flex items-center gap-2 px-4 py-2 bg-emerald-500/15 border border-emerald-500/30 rounded-lg text-emerald-300 text-[13px] font-medium cursor-pointer hover:bg-emerald-500/25 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
    >
      <LoadingSpinner v-if="props.isSharing" size="sm" />
      <svg v-else-if="!copiedShare" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
      </svg>
      <svg v-else class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
      </svg>
      {{ props.isSharing ? t('shopping.sharing') : copiedShare ? t('shopping.linkCopied') : t('common.actions.share') }}
    </button>
  </div>
</template>
