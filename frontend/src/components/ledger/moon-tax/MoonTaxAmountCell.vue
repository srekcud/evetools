<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'

defineProps<{
  amount: number | null
  unknownTypeIds: number[]
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()
</script>

<template>
  <td :data-unknown="amount === null ? '' : undefined" class="px-3 py-2 text-right text-sm whitespace-nowrap">
    <span v-if="amount !== null" class="text-slate-200">{{ formatIsk(amount) }}</span>
    <template v-else>
      <span class="text-amber-400"><span aria-hidden="true">⚠ </span>{{ t('ledger.mining.moonTax.unknown') }}</span>
      <span v-if="unknownTypeIds.length > 0" class="block text-xs text-amber-500/80">
        {{ t('ledger.mining.moonTax.withoutPrice', { ids: unknownTypeIds.join(', ') }) }}
      </span>
    </template>
  </td>
</template>
