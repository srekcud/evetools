<script setup lang="ts">
import { useI18n } from 'vue-i18n'

withDefaults(defineProps<{
  percent: number | null | undefined
  tooltipKey?: string
}>(), {
  tooltipKey: 'industry.partialPrice.tooltip',
})

const { t } = useI18n()
</script>

<template>
  <span v-if="percent != null" class="ml-1.5 text-xs font-sans text-amber-400 cursor-help" :title="t(tooltipKey)">{{ t('industry.partialPrice.marker', { percent }) }}</span>
</template>
