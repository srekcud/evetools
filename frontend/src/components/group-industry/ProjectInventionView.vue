<script setup lang="ts">
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupInventionStore } from '@/stores/group-industry/invention'
import type { BomItem, ProjectInventionOption, ProjectInventionProduct } from '@/stores/group-industry/types'
import { buildInventionSteps, type InventionDotState, type InventionStageState, type InventionStep } from './projectInventionView'

const props = defineProps<{
  projectId: string
  bomItems: BomItem[]
  now: number
}>()

const MS_PER_SECOND = 1000
const ICON_SIZE = 64

const { t } = useI18n()
const { formatNumber, formatDuration } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
const inventionStore = useGroupInventionStore()

const steps = computed(() => buildInventionSteps(props.bomItems, props.now))

const DOT_CLASSES: Record<InventionDotState, string> = {
  empty: 'border-blue-400/60',
  busy: 'border-blue-400/60 bg-blue-400/45',
  win: 'border-emerald-400 bg-emerald-400',
  lose: 'border-slate-700 bg-slate-700',
  unknown: 'border-dashed border-slate-400 bg-slate-600/40',
}

const STAGE_CLASSES: Record<InventionStageState, string> = {
  wait: 'opacity-45',
  run: '[&_.stage-icon]:border-cyan-400 [&_.stage-icon]:shadow-[0_0_8px_rgba(0,212,255,0.4)]',
  done: '[&_.stage-icon]:border-emerald-400',
}

const ARROW_CLASSES: Record<InventionStageState, string> = {
  wait: 'bg-slate-600',
  run: 'bg-cyan-400',
  done: 'bg-emerald-400',
}

function productOf(step: InventionStep): ProjectInventionProduct | null {
  return inventionStore.products?.find(product => product.typeId === step.productTypeId) ?? null
}

function selectedOptionOf(step: InventionStep): ProjectInventionOption | null {
  const product = productOf(step)
  if (product === null) return null
  return product.invention.options.find(option => option.decryptorTypeId === product.selectedDecryptorTypeId) ?? null
}

function datacoresOf(step: InventionStep): string | null {
  const product = productOf(step)
  if (product === null) return null
  return product.invention.datacores.map(name => name ?? t('common.unknownName')).join(' + ')
}

function copyYieldOf(step: InventionStep): string | null {
  const option = selectedOptionOf(step)
  if (option === null) return null
  return `${t('groupIndustry.inventionView.bpcRuns', { runs: option.runs })} · ${t('groupIndustry.inventionView.bpcEfficiency', { me: option.me, te: option.te })}`
}

function bpcStateOf(step: InventionStep): InventionStageState {
  return step.obtainedCopies === null ? 'wait' : 'done'
}

function bpcTitleOf(step: InventionStep): string {
  if (step.obtainedCopies !== null) return t('groupIndustry.inventionView.bpcObtained', { count: formatNumber(step.obtainedCopies, 0) })
  if (step.expectedCopies !== null) return t('groupIndustry.inventionView.bpcExpected', { count: formatNumber(step.expectedCopies, 1) })
  return t('groupIndustry.inventionView.bpcUnknown')
}

function inventionArrowOf(step: InventionStep): string {
  const attempts = step.attempts === null ? t('industry.inventionUnknown.label') : formatNumber(step.attempts, 0)
  const chance = step.chancePercent === null ? t('industry.inventionUnknown.label') : formatNumber(step.chancePercent, 1)
  const label = t('groupIndustry.inventionView.inventionArrow', { attempts, chance })
  return step.remainingMs === null ? label : `${label} · ${formatDuration(Math.floor(step.remainingMs / MS_PER_SECOND))}`
}

function captionOf(step: InventionStep): string {
  if (step.caption === 'result') return t('groupIndustry.inventionView.caption.result', { successes: formatNumber(step.successes, 0), failures: formatNumber(step.failures, 0) })
  if (step.caption === 'running') return t('groupIndustry.inventionView.caption.running')
  if (step.caption === 'unknownResult') return t('groupIndustry.inventionView.caption.unknownResult')
  return step.expectedCopies === null
    ? t('groupIndustry.inventionView.caption.idleUnknown')
    : t('groupIndustry.inventionView.caption.idle', { count: formatNumber(step.expectedCopies, 1) })
}

function dotTitleOf(state: InventionDotState, index: number): string {
  return t(`groupIndustry.inventionView.dot.${state}`, { number: index + 1 })
}

watch(() => props.projectId, projectId => inventionStore.fetchInvention(projectId, null), { immediate: true })
</script>

<template>
  <section v-if="steps.length > 0" data-testid="invention-view" class="mb-6 space-y-4">
    <article
      v-for="step in steps"
      :key="step.productTypeId"
      :data-testid="`invention-view-product-${step.productTypeId}`"
      class="border border-blue-400/45 bg-gradient-to-b from-blue-400/10 to-transparent px-3 py-2.5"
    >
      <header class="mb-2.5 flex flex-wrap items-baseline gap-x-2.5 gap-y-1 text-xs">
        <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-blue-300">{{ t('groupIndustry.inventionView.title') }}</h2>
        <span class="text-slate-400">{{ t('groupIndustry.inventionView.intro', { product: step.productName }) }}</span>
      </header>

      <p v-if="inventionStore.error !== null" data-testid="invention-view-error" role="alert" class="mb-2 text-xs text-amber-400">
        {{ t('groupIndustry.inventionView.detailsError') }} {{ inventionStore.error }}
      </p>

      <div class="flex items-center gap-1.5 overflow-x-auto pb-1.5">
        <div class="flex min-w-[92px] max-w-[110px] flex-col items-center gap-0.5 text-center" :class="STAGE_CLASSES.done" data-testid="invention-view-stage-bpo">
          <span class="stage-icon flex h-10 w-10 items-center justify-center border border-violet-400 bg-black font-mono text-[10px] text-violet-300">BPO</span>
          <b class="font-display text-[12.5px] text-slate-100">{{ t('groupIndustry.inventionView.bpo.title') }}</b>
          <small class="text-[10.5px] text-slate-400">{{ t('groupIndustry.inventionView.bpo.subtitle') }}</small>
        </div>

        <div class="flex min-w-[86px] max-w-[140px] flex-1 flex-col items-center">
          <span class="mb-0.5 text-center text-[10.5px] leading-tight text-slate-400">{{ t('groupIndustry.inventionView.copyArrow') }}</span>
          <i class="block h-0.5 w-full" :class="ARROW_CLASSES[step.inputsState]" />
        </div>

        <div class="flex min-w-[92px] max-w-[110px] flex-col items-center gap-0.5 text-center" :class="STAGE_CLASSES[step.inputsState]" data-testid="invention-view-stage-copies">
          <span class="stage-icon flex h-10 w-10 items-center justify-center border border-violet-400 bg-black font-mono text-[10px] text-violet-300">BPC</span>
          <b class="font-display text-[12.5px] text-slate-100">{{ step.attempts === null ? t('industry.inventionUnknown.label') : t('groupIndustry.inventionView.copies.title', { count: formatNumber(step.attempts, 0) }) }}</b>
          <small class="text-[10.5px] text-slate-400">{{ t('groupIndustry.inventionView.copies.subtitle') }}</small>
        </div>

        <span class="font-display text-lg font-bold text-slate-400">+</span>

        <div class="flex min-w-[92px] max-w-[130px] flex-col items-center gap-0.5 text-center" :class="STAGE_CLASSES[step.inputsState]" data-testid="invention-view-stage-datacores">
          <span class="stage-icon flex h-10 w-10 items-center justify-center border border-slate-600 bg-black font-mono text-[10px] text-slate-300">DC</span>
          <b class="font-display text-[12.5px] text-slate-100">{{ t('groupIndustry.inventionView.datacores.title') }}</b>
          <small class="text-[10.5px] text-slate-400">{{ datacoresOf(step) ?? t('groupIndustry.inventionView.datacores.subtitle') }}</small>
        </div>

        <div class="flex min-w-[86px] max-w-[140px] flex-1 flex-col items-center" data-testid="invention-view-arrow-invention">
          <span class="mb-0.5 text-center text-[10.5px] leading-tight text-slate-400">{{ inventionArrowOf(step) }}</span>
          <i class="block h-0.5 w-full" :class="ARROW_CLASSES[step.inventionState]" />
        </div>

        <div class="flex min-w-[150px] flex-col items-center gap-1">
          <div class="grid grid-cols-[repeat(15,8px)] gap-[3px]">
            <i
              v-for="(state, index) in step.dots"
              :key="index"
              data-testid="invention-view-dot"
              :data-state="state"
              :title="dotTitleOf(state, index)"
              class="block h-2 w-2 border"
              :class="DOT_CLASSES[state]"
            />
          </div>
          <small data-testid="invention-view-dots-caption" class="text-center text-[10.5px] text-slate-400" :class="{ 'font-semibold text-emerald-400': step.caption === 'result' }">{{ captionOf(step) }}</small>
        </div>

        <div class="flex min-w-[86px] max-w-[140px] flex-1 flex-col items-center">
          <span class="mb-0.5 text-center text-[10.5px] leading-tight text-slate-400">{{ t('groupIndustry.inventionView.successArrow') }}</span>
          <i class="block h-0.5 w-full" :class="ARROW_CLASSES[bpcStateOf(step)]" />
        </div>

        <div class="flex min-w-[92px] max-w-[120px] flex-col items-center gap-0.5 text-center" :class="STAGE_CLASSES[bpcStateOf(step)]" data-testid="invention-view-stage-bpc">
          <span class="stage-icon relative h-10 w-10 border border-slate-700 bg-black">
            <img :src="getTypeIconUrl(step.productTypeId, ICON_SIZE)" alt="" class="block h-10 w-10" @error="onImageError" />
            <span class="absolute inset-0 border border-violet-400" />
          </span>
          <b class="font-display text-[12.5px] text-slate-100">{{ bpcTitleOf(step) }}</b>
          <small class="text-[10.5px] text-slate-400">{{ copyYieldOf(step) ?? '' }}</small>
        </div>

        <template v-if="step.manufacturing !== null">
          <div class="flex min-w-[86px] max-w-[140px] flex-1 flex-col items-center">
            <span class="mb-0.5 text-center text-[10.5px] leading-tight text-slate-400">{{ t('groupIndustry.inventionView.manufacturingArrow') }}</span>
            <i class="block h-0.5 w-full" :class="ARROW_CLASSES[step.manufacturing.state]" />
          </div>
          <div class="flex min-w-[92px] max-w-[120px] flex-col items-center gap-0.5 text-center" :class="STAGE_CLASSES[step.manufacturing.state]" data-testid="invention-view-stage-product">
            <span class="stage-icon block h-10 w-10 border border-slate-700 bg-black">
              <img :src="getTypeIconUrl(step.manufacturing.typeId, ICON_SIZE)" alt="" class="block h-10 w-10" @error="onImageError" />
            </span>
            <b class="font-display text-[12.5px] text-slate-100">{{ formatNumber(step.manufacturing.quantity, 0) }} {{ step.manufacturing.typeName }}</b>
          </div>
        </template>
      </div>

      <div data-testid="invention-view-notes" class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-[11.5px] text-slate-400">
        <span>{{ t('groupIndustry.inventionView.notes.consumption') }}</span>
        <span>{{ t('groupIndustry.inventionView.notes.randomness') }}</span>
        <span>{{ t('groupIndustry.inventionView.notes.decryptor') }}</span>
      </div>
    </article>
  </section>
</template>
