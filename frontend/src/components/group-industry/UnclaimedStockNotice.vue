<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { UnclaimedStockLine } from '@/stores/group-industry/types'

defineProps<{
  lines: UnclaimedStockLine[]
}>()

const { t } = useI18n()
</script>

<template>
  <div data-testid="unclaimed-stock-notice" class="space-y-1">
    <p>{{ t('groupIndustry.distribution.unclaimedStockNotice') }}</p>
    <p class="text-xs">{{ t('groupIndustry.distribution.unclaimedStockLines') }}</p>
    <ul class="text-xs font-mono list-disc list-inside">
      <li v-for="line in lines" :key="line.bomLineId">{{ line.typeName }} × {{ line.quantity }}</li>
    </ul>
  </div>
</template>
