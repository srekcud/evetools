<script setup lang="ts">
import { useI18n } from 'vue-i18n'

export interface PeriodOption {
  value: number
  label: string
}

defineProps<{
  options: PeriodOption[]
  ytdDays: number
  allDays: number | null
}>()

const selectedDays = defineModel<number>({ required: true })

const { t } = useI18n()

function periodButtonClass(selected: boolean): string[] {
  return [
    'px-3 py-1.5 rounded-md text-sm font-medium transition-colors',
    selected ? 'bg-cyan-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-700/50',
  ]
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-2 rounded-lg bg-slate-900 p-1 w-fit max-w-full" role="group" :aria-label="t('ledger.period.label')">
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      :class="periodButtonClass(selectedDays === option.value)"
      @click="selectedDays = option.value"
    >{{ option.label }}</button>
    <button
      type="button"
      :class="periodButtonClass(selectedDays === ytdDays)"
      @click="selectedDays = ytdDays"
    >YTD</button>
    <button
      v-if="allDays !== null"
      type="button"
      :class="periodButtonClass(selectedDays === allDays)"
      @click="selectedDays = allDays"
    >{{ t('ledger.period.all') }}</button>
  </div>
</template>
