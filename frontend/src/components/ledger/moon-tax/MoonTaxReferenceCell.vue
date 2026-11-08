<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  reference: string | null
}>()

const { t } = useI18n()
</script>

<template>
  <td class="px-3 py-2 font-mono text-xs whitespace-nowrap text-slate-300">
    <template v-if="reference !== null">{{ reference }}</template>
    <span v-else class="font-sans uppercase tracking-wider text-slate-500">{{ t('ledger.mining.moonTax.periodInProgress') }}</span>
  </td>
</template>
