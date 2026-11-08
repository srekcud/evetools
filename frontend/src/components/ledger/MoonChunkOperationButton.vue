<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  moon: string
}>()

defineEmits<{ create: [] }>()

const { t } = useI18n()
</script>

<template>
  <button
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
