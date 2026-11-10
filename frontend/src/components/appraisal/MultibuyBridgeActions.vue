<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useAppraisalHandoffStore } from '@/stores/appraisalHandoff'
import { multibuyText } from '@/utils/multibuy'

const COPIED_FEEDBACK_MS = 2000

const props = withDefaults(defineProps<{
  lines: { typeName: string; quantity: number }[]
  showCopy?: boolean
}>(), { showCopy: true })

const { t } = useI18n()
const router = useRouter()

const copied = ref(false)
let copiedTimer: ReturnType<typeof setTimeout> | null = null

const isEmpty = computed(() => props.lines.length === 0)

async function copyMultibuy(): Promise<void> {
  await navigator.clipboard.writeText(multibuyText(props.lines))
  copied.value = true
  if (copiedTimer !== null) clearTimeout(copiedTimer)
  copiedTimer = setTimeout(() => { copied.value = false }, COPIED_FEEDBACK_MS)
}

async function evaluateInAppraisal(): Promise<void> {
  useAppraisalHandoffStore().send(multibuyText(props.lines))
  await router.push({ name: 'appraisal' })
}

onBeforeUnmount(() => {
  if (copiedTimer !== null) clearTimeout(copiedTimer)
})
</script>

<template>
  <div class="flex items-center gap-2">
    <button
      v-if="showCopy"
      type="button"
      data-testid="multibuy-copy"
      :disabled="isEmpty"
      class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 disabled:opacity-50 disabled:cursor-not-allowed text-slate-300 text-xs font-medium rounded-lg flex items-center gap-1.5 transition-colors border border-slate-700"
      @click="copyMultibuy"
    >
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" />
      </svg>
      <span v-if="copied">{{ t('industry.bridges.copied') }}</span>
      <span v-else>{{ t('industry.bridges.copyMultibuy') }}</span>
    </button>
    <button
      type="button"
      data-testid="multibuy-evaluate"
      :disabled="isEmpty"
      class="px-3 py-1.5 bg-cyan-500/10 hover:bg-cyan-500/20 disabled:opacity-50 disabled:cursor-not-allowed text-cyan-300 text-xs font-medium rounded-lg flex items-center gap-1.5 transition-colors border border-cyan-500/30"
      @click="evaluateInAppraisal"
    >
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
      </svg>
      {{ t('industry.bridges.evaluate') }}
    </button>
  </div>
</template>
