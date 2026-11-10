<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MiningOperation, MiningOperationLedger, MiningOperationValuation } from '@/stores/miningOperation'
import { buildShareRecap, shareRecapLines } from '@/views/MiningOperation/miningOperationShareRecap'
import { canDrawRecapImage, drawRecapImage, recapImageBlob, type RecapImageLine, type RecapLineStyle } from '@/views/MiningOperation/miningOperationShareRecapImage'

const COPIED_FEEDBACK_MILLISECONDS = 2000

const LINE_CLASSES: Record<RecapLineStyle, string> = {
  kicker: 'text-xs font-semibold uppercase tracking-[0.2em] text-cyan-400',
  title: 'text-lg font-bold text-cyan-100',
  body: 'text-sm text-slate-200',
  footer: 'text-xs text-slate-400',
}

const props = defineProps<{
  operation: MiningOperation
  valuation: MiningOperationValuation
  ledger: MiningOperationLedger
  marketLabel: string
  sitesCount?: number | null
}>()

const { t } = useI18n()

const settings = computed(() => props.valuation.settings)
const basis = computed(() => t('miningOperation.valuation.basis', {
  form: t(`miningOperation.valuation.form.${settings.value.valuationForm}`),
  market: props.marketLabel,
  side: t(`miningOperation.valuation.side.${settings.value.valuationSide}`),
}))
const recap = computed(() => buildShareRecap(props.operation, props.valuation, props.ledger, t('miningOperation.unknownPilot'), props.sitesCount ?? null))
const lines = computed(() => shareRecapLines(recap.value, basis.value, t))
const text = computed(() => lines.value.join('\n'))

const imageLines = computed<RecapImageLine[]>(() => [
  { text: t('miningOperation.shareRecap.previewKicker'), style: 'kicker' },
  ...lines.value.map((line, index): RecapImageLine => ({
    text: line,
    style: index === 0 ? 'title' : index === lines.value.length - 1 ? 'footer' : 'body',
  })),
])

const imageAvailable = typeof ClipboardItem !== 'undefined' && canDrawRecapImage()

const textCopied = ref(false)
const imageCopied = ref(false)
const copyFailed = ref(false)
const feedbackTimers: ReturnType<typeof setTimeout>[] = []

function showCopied(flag: typeof textCopied): void {
  flag.value = true
  feedbackTimers.push(setTimeout(() => { flag.value = false }, COPIED_FEEDBACK_MILLISECONDS))
}

async function copyText(): Promise<void> {
  copyFailed.value = false
  try {
    await navigator.clipboard.writeText(text.value)
    showCopied(textCopied)
  } catch {
    copyFailed.value = true
  }
}

async function copyImage(): Promise<void> {
  copyFailed.value = false
  try {
    const canvas = document.createElement('canvas')
    drawRecapImage(canvas, imageLines.value)
    const blob = await recapImageBlob(canvas)
    await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })])
    showCopied(imageCopied)
  } catch {
    copyFailed.value = true
  }
}

onBeforeUnmount(() => feedbackTimers.forEach(clearTimeout))
</script>

<template>
  <section class="eve-card p-5">
    <h2 class="mb-1 font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.shareRecap.title') }}</h2>
    <p class="mb-3 text-xs text-slate-400">{{ t('miningOperation.shareRecap.hint') }} · {{ t('miningOperation.shareRecap.noNames') }}</p>
    <div class="grid gap-4 lg:grid-cols-2">
      <div class="min-w-0">
        <pre data-test="recap-text" class="whitespace-pre-wrap break-words rounded-sm border border-slate-700 bg-slate-950/60 p-3 font-mono text-xs text-slate-200">{{ text }}</pre>
        <button
          type="button"
          data-test="copy-recap-text"
          class="mt-3 rounded-md border border-cyan-500/40 px-4 py-2 text-sm text-cyan-200 hover:bg-cyan-500/10"
          @click="copyText"
        >{{ textCopied ? t('miningOperation.shareRecap.copied') : t('miningOperation.shareRecap.copyText') }}</button>
      </div>
      <div class="min-w-0">
        <div data-test="recap-preview" class="space-y-1 rounded-sm border-2 border-cyan-600 bg-slate-950 p-4 font-mono">
          <p v-for="(line, index) in imageLines" :key="index" class="break-words" :class="LINE_CLASSES[line.style]">{{ line.text }}</p>
        </div>
        <p class="mt-2 text-xs text-slate-400">{{ t('miningOperation.shareRecap.imageHint') }}</p>
        <button
          type="button"
          data-test="copy-recap-image"
          class="mt-3 rounded-md border border-cyan-500/40 px-4 py-2 text-sm text-cyan-200 hover:bg-cyan-500/10 disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="!imageAvailable"
          :title="imageAvailable ? undefined : t('miningOperation.shareRecap.imageUnavailable')"
          @click="copyImage"
        >{{ imageCopied ? t('miningOperation.shareRecap.copied') : t('miningOperation.shareRecap.copyImage') }}</button>
      </div>
    </div>
    <p v-if="copyFailed" class="mt-3 rounded-sm border border-amber-500/30 bg-amber-500/5 px-3 py-1.5 text-xs text-amber-200" role="alert">{{ t('miningOperation.shareRecap.copyFailed') }}</p>
  </section>
</template>
