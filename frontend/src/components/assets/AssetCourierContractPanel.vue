<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatCubicMeters, type VolumeSummary } from '@/utils/assetVolume'

const COPY_FEEDBACK_DURATION_MS = 2000

const props = defineProps<{
  locationNames: string[]
  volume: VolumeSummary
}>()

defineEmits<{
  close: []
}>()

const { t } = useI18n()

const copyState = ref<'idle' | 'copied' | 'failed'>('idle')
let resetTimer: ReturnType<typeof setTimeout> | null = null

const volumeText = computed(() => t('assets.volume.amount', { amount: formatCubicMeters(props.volume.cubicMeters) }))

const contractText = computed(() => {
  const lines = [
    t('assets.courier.title'),
    `${t('assets.courier.from')}: ${props.locationNames.join(', ')}`,
    `${t('assets.courier.volume')}: ${volumeText.value}`,
  ]
  if (props.volume.unknownTypeCount > 0) {
    lines.push(`${t('assets.courier.unknownTypes')}: ${props.volume.unknownTypeCount}`)
  }
  return lines.join('\n')
})

const copyLabel = computed(() => {
  if (copyState.value === 'copied') return t('assets.courier.copied')
  if (copyState.value === 'failed') return t('assets.courier.copyFailed')
  return t('assets.courier.copy')
})

async function copyContract(): Promise<void> {
  try {
    await navigator.clipboard.writeText(contractText.value)
    copyState.value = 'copied'
  } catch {
    copyState.value = 'failed'
  }
  if (resetTimer !== null) clearTimeout(resetTimer)
  resetTimer = setTimeout(() => {
    copyState.value = 'idle'
  }, COPY_FEEDBACK_DURATION_MS)
}

onBeforeUnmount(() => {
  if (resetTimer !== null) clearTimeout(resetTimer)
})
</script>

<template>
  <div
    data-testid="courier-contract-panel"
    class="rounded-lg border border-cyan-500/30 bg-slate-900/95 px-5 py-3 shadow-lg backdrop-blur space-y-2"
  >
    <div class="flex items-center justify-between gap-3">
      <h3 class="text-sm font-medium text-cyan-300">{{ t('assets.courier.title') }}</h3>
      <button
        type="button"
        data-testid="courier-contract-close"
        class="text-xs text-slate-400 hover:text-slate-200"
        @click="$emit('close')"
      >{{ t('assets.courier.close') }}</button>
    </div>
    <pre data-testid="courier-contract-text" class="whitespace-pre-wrap rounded-sm bg-slate-950/60 px-3 py-2 text-xs text-slate-300 font-mono">{{ contractText }}</pre>
    <div class="flex items-center justify-between gap-3">
      <p class="text-xs text-slate-500">{{ t('assets.courier.hint') }}</p>
      <button
        type="button"
        data-testid="courier-contract-copy"
        class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-lg transition-colors border border-slate-700"
        @click="copyContract"
      >{{ copyLabel }}</button>
    </div>
  </div>
</template>
