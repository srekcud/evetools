<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const FEEDBACK_DURATION_MS = 2000

const props = defineProps<{
  blueprintName: string | null
}>()

const { t } = useI18n()

const state = ref<'idle' | 'copied' | 'failed'>('idle')
let resetTimer: ReturnType<typeof setTimeout> | null = null

const label = computed((): string => {
  if (state.value === 'copied') return t('groupIndustry.planning.blueprintNameCopied')
  if (state.value === 'failed') return t('groupIndustry.planning.blueprintNameCopyFailed')
  return t('groupIndustry.planning.copyBlueprintName', { name: props.blueprintName ?? '' })
})

async function copy(): Promise<void> {
  if (props.blueprintName === null) return
  try {
    await navigator.clipboard.writeText(props.blueprintName)
    state.value = 'copied'
  } catch {
    state.value = 'failed'
  }
  if (resetTimer !== null) clearTimeout(resetTimer)
  resetTimer = setTimeout(() => {
    state.value = 'idle'
  }, FEEDBACK_DURATION_MS)
}

onBeforeUnmount(() => {
  if (resetTimer !== null) clearTimeout(resetTimer)
})
</script>

<template>
  <button
    v-if="blueprintName !== null"
    type="button"
    data-testid="planning-copy-blueprint-name"
    :data-state="state"
    :title="label"
    :aria-label="label"
    class="shrink-0 inline-flex items-center justify-center w-5 h-5 rounded-sm transition-colors"
    :class="{
      'text-slate-500 hover:text-cyan-300': state === 'idle',
      'text-emerald-400': state === 'copied',
      'text-red-400': state === 'failed',
    }"
    @click="copy"
  >
    <svg v-if="state === 'copied'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
    </svg>
    <svg v-else-if="state === 'failed'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
    </svg>
    <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" />
    </svg>
  </button>
</template>
