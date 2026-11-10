<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  moon: string
  expanded: boolean
}>()

defineEmits<{ toggle: [] }>()

const { t } = useI18n()
</script>

<template>
  <button
    type="button"
    data-miners-toggle
    :aria-expanded="expanded"
    :title="t('ledger.mining.moonChunks.miners.toggleFor', { moon })"
    :aria-label="t('ledger.mining.moonChunks.miners.toggleFor', { moon })"
    class="rounded-sm border border-slate-600 px-2 py-0.5 text-left text-xs font-medium uppercase tracking-wider text-slate-200 transition-colors hover:border-cyan-500/60 hover:text-cyan-300"
    @click="$emit('toggle')"
  >
    <span aria-hidden="true">{{ expanded ? '▾' : '▸' }} </span>{{ t('ledger.mining.moonChunks.miners.toggle') }}
  </button>
</template>
