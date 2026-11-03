<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MiningOperationStatus, MiningSplitMode } from '@/stores/miningOperation'

const PRORATA_STEPS = ['planned', 'in_progress', 'closing', 'shares', 'settled'] as const
const EACH_FOR_THEMSELVES_STEPS = ['planned', 'in_progress', 'closing', 'completed'] as const

const props = defineProps<{
  status: MiningOperationStatus
  splitMode: MiningSplitMode
}>()

const { t } = useI18n()

const steps = computed<readonly string[]>(() => (props.splitMode === 'value_prorata' ? PRORATA_STEPS : EACH_FOR_THEMSELVES_STEPS))
const currentIndex = computed(() => {
  if (props.status === 'completed') return steps.value.length - 1
  return steps.value.indexOf(props.status)
})

const archived = computed(() => props.status === 'settled' || props.status === 'completed')

function stateOf(index: number): 'done' | 'current' | 'todo' {
  if (index < currentIndex.value || (archived.value && index === currentIndex.value)) return 'done'
  return index === currentIndex.value ? 'current' : 'todo'
}

const STATE_CLASS = {
  done: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300',
  current: 'border-cyan-400 bg-cyan-500/15 text-cyan-200',
  todo: 'border-slate-700 bg-slate-900 text-slate-500',
} as const
</script>

<template>
  <ol class="flex flex-wrap items-center gap-1.5">
    <li
      v-for="(step, index) in steps"
      :key="step"
      class="rounded-sm border px-2.5 py-1 font-mono text-xs uppercase tracking-wider"
      :class="STATE_CLASS[stateOf(index)]"
      :aria-current="index === currentIndex ? 'step' : undefined"
    >{{ t(`miningOperation.steps.${step}`) }}</li>
  </ol>
</template>
