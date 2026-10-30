<script setup lang="ts">
import { computed, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { DetectedLootSale } from '@/types/pve'

const props = defineProps<{
  detectedSale: DetectedLootSale
  isImporting: boolean
}>()

const emit = defineEmits<{
  importSale: []
  ignoreSale: []
}>()

const { t } = useI18n()
const { formatIskFull } = useFormatters()

const sale = computed(() => reactive(props.detectedSale))
</script>

<template>
  <div class="flex flex-col items-end gap-2">
    <div v-if="sale.price === null || ((sale.source === 'contract' || sale.source === 'corp_project') && sale.price === 0)" class="flex items-center gap-1">
      <input
        type="number"
        v-model.number="sale.price"
        :aria-label="sale.typeName"
        class="w-28 px-2 py-1 text-right bg-slate-700 border border-slate-600 rounded-sm text-amber-400 font-mono text-sm focus:outline-hidden focus:border-amber-500"
        min="0"
        step="100000"
        :placeholder="sale.source === 'corp_project' ? 'Valeur' : 'Prix'"
      />
      <span class="text-xs text-slate-500">ISK</span>
    </div>
    <p v-else class="text-amber-400 font-mono text-sm">+{{ formatIskFull(sale.price) }}</p>
    <div class="flex gap-2">
      <button
        @click="emit('importSale')"
        :disabled="isImporting || sale.price === null || ((sale.source === 'contract' || sale.source === 'corp_project') && sale.price <= 0)"
        class="px-3 py-1 text-xs bg-emerald-600 hover:bg-emerald-500 disabled:bg-slate-600 disabled:cursor-not-allowed rounded-sm text-white font-medium"
      >
        {{ t('common.actions.add') }}
      </button>
      <button
        @click="emit('ignoreSale')"
        :disabled="isImporting"
        class="px-3 py-1 text-xs bg-slate-600 hover:bg-slate-500 disabled:opacity-50 rounded-sm text-slate-300 font-medium"
      >
        {{ t('pve.ignore') }}
      </button>
    </div>
  </div>
</template>
