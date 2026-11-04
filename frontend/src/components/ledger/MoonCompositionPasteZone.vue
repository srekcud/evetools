<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ValidationError } from '@/services/api'
import { recordMoonComposition } from '@/composables/useCorporationMiningExtractions'

const LINE_ERROR_PATH = /^scan\[(\d+)\]$/
const SCAN_ERROR_KEY = 'scan'

const props = defineProps<{
  corporationId: number
  moonId: number
}>()

const emit = defineEmits<{ recorded: [] }>()

const { t } = useI18n()

const scan = ref('')
const isRecording = ref(false)
const refusals = ref<Array<{ key: string; message: string }>>([])
const hasFailed = ref(false)

const canRecord = computed(() => scan.value.trim() !== '' && !isRecording.value)

function refusalKey(propertyPath: string): string {
  return LINE_ERROR_PATH.exec(propertyPath)?.[1] ?? SCAN_ERROR_KEY
}

async function record(): Promise<void> {
  isRecording.value = true
  refusals.value = []
  hasFailed.value = false
  try {
    await recordMoonComposition(props.corporationId, props.moonId, scan.value)
    scan.value = ''
    emit('recorded')
  } catch (e) {
    if (e instanceof ValidationError) {
      refusals.value = e.violations.map(violation => ({ key: refusalKey(violation.propertyPath), message: violation.message }))
    } else {
      console.error('Failed to record the moon composition:', e)
      hasFailed.value = true
    }
  } finally {
    isRecording.value = false
  }
}
</script>

<template>
  <div data-scan-zone class="space-y-2">
    <label class="block text-xs font-medium uppercase tracking-wider text-slate-400" :for="`scan-${moonId}`">
      {{ t('ledger.mining.moonChunks.value.paste.label') }}
    </label>
    <p class="text-xs text-slate-500">{{ t('ledger.mining.moonChunks.value.paste.hint') }}</p>
    <textarea
      :id="`scan-${moonId}`"
      v-model="scan"
      data-scan-input
      rows="6"
      spellcheck="false"
      :placeholder="t('ledger.mining.moonChunks.value.paste.placeholder')"
      class="w-full rounded-sm border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-sm text-slate-200 placeholder:text-slate-600 focus:border-cyan-500/60 focus:outline-none"
    />
    <ul v-if="refusals.length > 0" role="alert" class="space-y-1">
      <li
        v-for="(refusal, index) in refusals"
        :key="index"
        :data-scan-error="refusal.key"
        class="rounded-sm border border-red-500/40 bg-red-500/10 px-3 py-1.5 text-sm text-red-300"
      >
        <span aria-hidden="true">✕ </span>{{ refusal.message }}
      </li>
    </ul>
    <p v-if="hasFailed" data-scan-failure role="alert" class="rounded-sm border border-red-500/40 bg-red-500/10 px-3 py-1.5 text-sm text-red-300">
      <span aria-hidden="true">✕ </span>{{ t('ledger.mining.moonChunks.value.paste.failure') }}
    </p>
    <button
      type="button"
      data-record-composition
      :disabled="!canRecord"
      class="px-3 py-1 rounded-sm border border-cyan-500/60 bg-cyan-500/10 text-xs font-medium uppercase tracking-wider text-cyan-300 transition-colors hover:bg-cyan-500/20 disabled:opacity-40 disabled:cursor-not-allowed"
      @click="record"
    >
      {{ isRecording ? t('ledger.mining.moonChunks.value.paste.recording') : t('ledger.mining.moonChunks.value.paste.submit') }}
    </button>
  </div>
</template>
