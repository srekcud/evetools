<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { ProjectPriceAlert } from '@/stores/group-industry/types'

defineProps<{ priceAlert: ProjectPriceAlert }>()

defineEmits<{ 'view-sales': [] }>()

const { t } = useI18n()
const { formatIsk } = useFormatters()
</script>

<template>
  <div
    data-testid="price-alert-banner"
    role="alert"
    class="mb-4 flex items-center justify-between gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3"
  >
    <div class="flex min-w-0 items-start gap-3">
      <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
      </svg>
      <p class="break-words text-sm text-amber-200">
        <b class="font-semibold">{{ t('groupIndustry.cockpit.priceAlert.title', { jitaPrice: formatIsk(priceAlert.jitaUnitPrice), breakEven: formatIsk(priceAlert.breakEvenPerUnit) }) }}</b>
        {{ t('groupIndustry.cockpit.priceAlert.detail', { gap: formatIsk(priceAlert.gapPerUnit) }) }}
      </p>
    </div>
    <button
      data-testid="price-alert-view-sales"
      type="button"
      class="flex-shrink-0 rounded-lg border border-amber-500/30 bg-amber-500/15 px-3 py-1.5 text-xs font-medium text-amber-200 transition-colors hover:bg-amber-500/25"
      @click="$emit('view-sales')"
    >
      {{ t('groupIndustry.cockpit.priceAlert.button') }}
    </button>
  </div>
</template>
