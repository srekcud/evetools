<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { ChainNode } from '@/stores/group-industry/types'

const props = defineProps<{
  materials: ChainNode[]
}>()

const emit = defineEmits<{
  'open-sourcing': []
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()

const unknownCount = computed(() => props.materials.filter(node => node.requiredQuantity === null || node.fulfilledQuantity === null).length)
const suppliedCount = computed(() => props.materials.filter(node => node.requiredQuantity !== null && node.fulfilledQuantity !== null && node.fulfilledQuantity >= node.requiredQuantity).length)
</script>

<template>
  <button
    type="button"
    data-testid="chain-materials-summary"
    :title="t('groupIndustry.chain.flow.openSourcing')"
    class="text-left w-full rounded border border-slate-700 border-l-4 border-l-cyan-500 bg-slate-800/50 px-3 py-2 hover:bg-slate-800 transition-colors"
    @click="emit('open-sourcing')"
  >
    <span class="block text-sm font-semibold text-slate-200">{{ t('groupIndustry.chain.flow.materialsSummary', { supplied: formatNumber(suppliedCount, 0), total: formatNumber(materials.length, 0) }) }}</span>
    <span v-if="unknownCount > 0" class="block mt-1 text-xs text-violet-300">{{ t('groupIndustry.chain.flow.materialsSummaryUnknown', { count: formatNumber(unknownCount, 0) }) }}</span>
  </button>
</template>
