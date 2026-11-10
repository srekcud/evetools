<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { MoonChunkLinkedOperation } from '@/composables/useCorporationMiningExtractions'

defineProps<{
  moon: string
  linkedOperation: MoonChunkLinkedOperation | null
  linkedOperationCount: number
}>()

defineEmits<{ create: [] }>()

const { t } = useI18n()
</script>

<template>
  <div v-if="linkedOperation !== null" class="flex flex-wrap items-center gap-x-2">
    <RouterLink
      :to="{ name: 'mining-operation-detail', params: { id: linkedOperation.id } }"
      data-linked-operation
      :title="t('ledger.mining.moonChunks.linkedOperationFor', { label: linkedOperation.label })"
      class="text-xs font-medium text-cyan-300 hover:text-cyan-200"
    >{{ t('ledger.mining.moonChunks.linkedOperation') }}</RouterLink>
    <span
      v-if="linkedOperationCount > 1"
      data-linked-operation-more
      :title="t('ledger.mining.moonChunks.linkedOperationMoreTitle', { count: linkedOperationCount - 1 })"
      class="font-mono text-xs text-slate-400"
    >{{ t('ledger.mining.moonChunks.linkedOperationMore', { count: linkedOperationCount - 1 }) }}</span>
  </div>
  <button
    v-else
    type="button"
    data-action="create-operation"
    :title="t('ledger.mining.moonChunks.createOperationFor', { moon })"
    :aria-label="t('ledger.mining.moonChunks.createOperationFor', { moon })"
    class="rounded-sm border border-cyan-500/60 bg-cyan-500/10 px-2 py-0.5 text-left text-xs font-medium uppercase tracking-wider text-cyan-300 transition-colors hover:bg-cyan-500/20"
    @click="$emit('create')"
  >
    <span aria-hidden="true">＋ </span>{{ t('ledger.mining.moonChunks.createOperation') }}
  </button>
</template>
