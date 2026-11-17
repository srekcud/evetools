<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { changeArrow } from '@/utils/revenueGrid'

const props = defineProps<{
  change: number | null
  estimate?: boolean
  costLike?: boolean
}>()

const { t } = useI18n()

const colorClass = computed(() => {
  if (props.change === null) return 'text-slate-500'
  const rounded = Math.round(props.change)
  if (rounded === 0) return 'text-slate-400'
  const favourable = props.costLike ? rounded < 0 : rounded > 0
  return favourable ? 'text-emerald-400' : 'text-red-400'
})
</script>

<template>
  <span v-if="change === null" class="text-slate-500" :title="t('revenue.grid.changeUnknown')">—</span>
  <span v-else :class="colorClass">{{ estimate ? '~' : '' }}{{ changeArrow(change) }} {{ Math.abs(Math.round(change)) }} %</span>
</template>
