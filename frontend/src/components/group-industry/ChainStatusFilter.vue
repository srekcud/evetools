<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ChainNode, ChainStatus } from '@/stores/group-industry/types'
import { CHAIN_STATUSES, chainStatusChipClass, chainStatusDotClass } from './chainStatus'
import { toggleChainStatus } from './chainStatusFilter'

const props = defineProps<{
  nodes: ChainNode[]
  modelValue: readonly ChainStatus[]
}>()

const emit = defineEmits<{
  'update:modelValue': [statuses: ChainStatus[]]
}>()

const { t } = useI18n()

function countOf(status: ChainStatus): number {
  return props.nodes.filter(node => node.status === status).length
}

const statuses = computed(() => CHAIN_STATUSES.filter(status => countOf(status) > 0 || props.modelValue.includes(status)))
</script>

<template>
  <div class="flex flex-wrap items-center gap-1.5 px-4 py-2 border-b border-slate-800" data-testid="chain-status-filter" role="group" :aria-label="t('groupIndustry.chain.filter.label')">
    <span class="text-xs uppercase tracking-wider text-slate-500 mr-1">{{ t('groupIndustry.chain.filter.label') }}</span>
    <button
      v-for="status in statuses"
      :key="status"
      type="button"
      :data-testid="`chain-status-filter-${status}`"
      :aria-pressed="modelValue.includes(status)"
      class="inline-flex items-center gap-1.5 px-2 py-1 rounded-sm border text-xs whitespace-nowrap transition-opacity"
      :class="[chainStatusChipClass(status), modelValue.length === 0 || modelValue.includes(status) ? '' : 'opacity-40 hover:opacity-80']"
      @click="emit('update:modelValue', toggleChainStatus(modelValue, status))"
    >
      <i class="inline-block w-1.5 h-1.5 rounded-full" :class="chainStatusDotClass(status)"></i>{{ t(`groupIndustry.chain.status.${status}`) }}
      <span class="font-mono tabular-nums">{{ countOf(status) }}</span>
    </button>
    <button
      v-if="modelValue.length > 0"
      type="button"
      data-testid="chain-status-filter-clear"
      class="ml-1 px-2 py-1 text-xs text-slate-400 hover:text-slate-200 underline underline-offset-2"
      @click="emit('update:modelValue', [])"
    >{{ t('groupIndustry.chain.filter.clear') }}</button>
  </div>
</template>
