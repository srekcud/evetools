<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  percent: number | null | undefined
}>()

const { t } = useI18n()
</script>

<template>
  <span v-if="percent != null" class="ml-1.5 text-xs font-sans text-amber-400 cursor-help" :title="t('industry.partialPrice.tooltip')">{{ t('industry.partialPrice.marker', { percent }) }}</span>
</template>
