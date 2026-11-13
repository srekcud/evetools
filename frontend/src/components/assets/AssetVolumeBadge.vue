<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { formatCubicMeters, type VolumeSummary } from '@/utils/assetVolume'

defineProps<{
  volume: VolumeSummary
  testId: string
}>()

const { t } = useI18n()
</script>

<template>
  <span class="inline-flex items-center gap-2">
    <span
      :data-testid="testId"
      :title="t('assets.volume.label')"
      class="font-mono text-xs text-slate-400"
    >{{ t('assets.volume.amount', { amount: formatCubicMeters(volume.cubicMeters) }) }}</span>
    <span
      v-if="volume.unknownTypeCount > 0"
      data-testid="volume-unknown"
      class="text-xs text-amber-400"
    >{{ t('assets.volume.unknownTypes', { count: volume.unknownTypeCount }, volume.unknownTypeCount) }}</span>
  </span>
</template>
