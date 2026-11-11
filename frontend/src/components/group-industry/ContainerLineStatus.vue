<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { ContainerVerification } from '@/stores/group-industry/types'

defineProps<{
  verification: ContainerVerification
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()
</script>

<template>
  <div class="flex items-center gap-1 mt-1" data-testid="container-line-status" :data-status="verification.status">
    <template v-if="verification.status === 'verified'">
      <svg class="w-3 h-3 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
      <span class="text-xs text-emerald-400">
        {{ t('groupIndustry.container.statuses.verified') }}: {{ formatNumber(verification.containerQuantity, 0) }} / {{ formatNumber(verification.requiredQuantity, 0) }}
      </span>
    </template>
    <template v-else-if="verification.status === 'partial'">
      <svg class="w-3 h-3 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
      <span class="text-xs text-amber-400">
        {{ t('groupIndustry.container.statuses.partial') }}: {{ formatNumber(verification.containerQuantity, 0) }} / {{ formatNumber(verification.requiredQuantity, 0) }}
      </span>
    </template>
    <template v-else-if="verification.status === 'missing'">
      <svg class="w-3 h-3 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
      <span class="text-xs text-red-400">
        {{ t('groupIndustry.container.statuses.missing') }}
      </span>
    </template>
  </div>
</template>
