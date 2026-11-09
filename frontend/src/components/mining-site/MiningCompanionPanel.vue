<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { UNKNOWN_DASH, useMiningSiteFormat } from '@/composables/useMiningSiteFormat'
import type { MiningSite, MiningSiteScan, MiningSiteScanType } from '@/stores/miningSite'
import { formatCountdown, formatEveClock } from '@/views/MiningOperation/miningOperationTime'
import { COMPANION_SIZES, type CompanionSizeKey } from '@/views/MiningSite/miningCompanion'
import {
  COUNTING_TIERS,
  ETA_TIER_CLASSES,
  etaTierOf,
  isIrregularRate,
  isRescanDue,
  mostProfitableTypeOf,
  remainingVolumeRingOf,
} from '@/views/MiningSite/miningSiteEstimate'

const PERCENT_MAX = 100
const RING_RADIUS = 34
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS
const SIZE_KEYS = Object.keys(COMPANION_SIZES) as CompanionSizeKey[]
const OPERATION_IN_PROGRESS = 'in_progress'

const props = defineProps<{
  site: MiningSite | null
  scans: MiningSiteScan[]
  size: CompanionSizeKey
  now: Date
  rescanMinutes: number
  typeNames: Record<number, string>
  operation: { id: string; status: string; myDepartedAt: string | null } | null
  notice: string | null
}>()
const emit = defineEmits<{ paste: []; leave: []; 'open-desk': []; size: [size: CompanionSizeKey] }>()

const { t } = useI18n()
const { etaText, wholeText, valueText, lastScanText } = useMiningSiteFormat()

const confirmingLeave = ref(false)

const orderedScans = computed(() => [...props.scans].sort((first, second) => first.number - second.number))
const lastScan = computed(() => orderedScans.value[orderedScans.value.length - 1] ?? null)
const isActive = computed(() => props.site?.status === 'active')
const siteTitle = computed(() => props.site?.label ?? t('miningCompanion.title'))
const tier = computed(() => (props.site === null || lastScan.value === null ? null : etaTierOf(props.site.status, lastScan.value, props.now)))
const showsCountdown = computed(() => tier.value !== null && COUNTING_TIERS.includes(tier.value))
const countdown = computed(() => {
  const eta = lastScan.value?.trendEta
  return eta === null || eta === undefined ? null : formatCountdown(new Date(eta).getTime() - props.now.getTime())
})
const trendEtaText = computed(() => (tier.value === 'depleted' ? t('miningSite.eta.depleted') : etaText(lastScan.value?.trendEta)))
const etaClasses = computed(() => ETA_TIER_CLASSES[tier.value ?? 'unknown'])
const tierLabel = computed(() => (tier.value === null ? null : t(`miningSite.tier.${tier.value}`)))
const lastTypeId = computed(() => {
  const scan = lastScan.value
  if (scan === null || scan.trendEta === null || scan.trendEta === undefined) return null
  const siteEta = new Date(scan.trendEta).getTime()
  const lastType = scan.types.find(type => type.trendEta !== null && type.trendEta !== undefined && new Date(type.trendEta).getTime() === siteEta)
  return lastType === undefined ? null : lastType.typeId
})
const hasSingleScan = computed(() => isActive.value && orderedScans.value.length === 1)
const ring = computed(() => remainingVolumeRingOf(orderedScans.value))
const ringText = computed(() => (ring.value === null ? UNKNOWN_DASH : `${Math.round(ring.value)} %`))
const ringDash = computed(() => `${(RING_CIRCUMFERENCE * (ring.value === null ? 0 : Math.min(ring.value, PERCENT_MAX))) / PERCENT_MAX} ${RING_CIRCUMFERENCE}`)
const bestOre = computed(() => (lastScan.value === null ? null : mostProfitableTypeOf(lastScan.value)))
const rescanDue = computed(() => props.site !== null && isRescanDue(props.site, props.now, props.rescanMinutes))
const hasIrregularType = computed(() => lastScan.value?.types.some(isIrregularRate) ?? false)
const lastScanLine = computed(() => (isActive.value && props.site !== null ? lastScanText(props.site, props.now.getTime()) : null))
const missingPriceNames = computed(() => (lastScan.value?.missingPriceTypeIds ?? []).map(typeNameOf).join(', '))
const canLeave = computed(() => props.operation !== null && props.operation.status === OPERATION_IN_PROGRESS && props.operation.myDepartedAt === null)
const showsFooter = computed(() => props.size !== 'bar' || props.site === null)
const showsVolumeRing = computed(() => props.size !== 'bar' && props.site !== null)

function typeNameOf(typeId: number): string {
  return props.typeNames[typeId] ?? t('common.unknownTypeName', { typeId })
}

function etaCellText(eta: string | null | undefined): string {
  return eta === null || eta === undefined ? UNKNOWN_DASH : formatEveClock(eta)
}

function remainingText(type: MiningSiteScanType): string {
  return type.status === 'depleted' ? t('miningSite.eta.depleted') : wholeText(type.units)
}

function isNotMined(type: MiningSiteScanType): boolean {
  return type.etaUnknownReason === 'not_depleting'
}

function requestLeave(): void {
  if (!confirmingLeave.value) {
    confirmingLeave.value = true
    return
  }
  confirmingLeave.value = false
  emit('leave')
}
</script>

<template>
  <section class="flex min-h-full flex-col gap-2 p-2 font-mono text-slate-100" data-testid="companion-panel" :data-size="size">
    <header class="flex items-center gap-1">
      <h1 class="min-w-0 flex-1 truncate font-display text-sm font-semibold uppercase tracking-wider text-cyan-200" data-testid="companion-site" :title="siteTitle">{{ siteTitle }}</h1>
      <button
        v-for="sizeKey in SIZE_KEYS"
        :key="sizeKey"
        type="button"
        :data-testid="`companion-size-${sizeKey}`"
        :aria-label="t(`miningCompanion.sizes.${sizeKey}`)"
        :aria-pressed="size === sizeKey"
        :title="`${t(`miningCompanion.sizes.${sizeKey}`)} (${COMPANION_SIZES[sizeKey].key.toUpperCase()})`"
        class="rounded-sm border px-1.5 py-0.5 text-xs uppercase"
        :class="size === sizeKey ? 'border-cyan-400/60 bg-cyan-500/15 text-cyan-100' : 'border-slate-700 text-slate-400 hover:bg-slate-800'"
        @click="emit('size', sizeKey)"
      >{{ COMPANION_SIZES[sizeKey].key }}</button>
      <slot name="tools" />
    </header>

    <slot name="settings" />

    <p v-if="notice !== null" class="rounded-sm border border-amber-400/40 bg-amber-500/10 px-2 py-1 text-xs text-amber-200" role="status" data-testid="companion-notice">{{ notice }}</p>

    <template v-if="site !== null">
      <div class="flex items-end justify-between gap-2 rounded-sm border px-2 py-1" :class="etaClasses" :title="tierLabel ?? undefined">
        <p class="text-3xl leading-none" data-testid="companion-eta">{{ trendEtaText }}</p>
        <p v-if="showsCountdown" class="text-xl leading-none" data-testid="companion-countdown">{{ countdown }}</p>
      </div>

      <template v-if="size !== 'bar'">
        <div class="flex items-center gap-3">
          <div class="min-w-0 flex-1 space-y-1">
            <p v-if="tierLabel !== null" data-testid="companion-tier">
              <span class="inline-block rounded-sm border px-2 py-0.5 text-xs" :class="etaClasses">{{ tierLabel }}</span>
            </p>
            <p class="text-sm text-slate-300">
              <span class="text-xs text-slate-500">{{ t('miningSite.eta.recent') }}</span>
              <span class="ml-2" data-testid="companion-eta-recent">{{ etaText(lastScan?.recentEta) }}</span>
            </p>
            <p v-if="hasSingleScan" class="text-xs text-amber-300" data-testid="companion-single-scan">{{ t('miningSite.single.hint') }}</p>
            <p v-else-if="lastTypeId !== null" class="truncate text-sm text-slate-300" data-testid="companion-last-ore" :title="typeNameOf(lastTypeId)">
              {{ t('miningSite.eta.lastType') }}
              <b class="font-semibold text-slate-100">{{ typeNameOf(lastTypeId) }}</b>
            </p>
            <p v-if="lastScan === null" class="text-xs text-slate-400" data-testid="companion-no-scan">{{ t('miningCompanion.noScan') }}</p>
          </div>
          <div v-if="showsVolumeRing" class="relative h-20 w-20 shrink-0" data-testid="companion-ring">
            <svg viewBox="0 0 80 80" class="h-20 w-20 -rotate-90" aria-hidden="true">
              <circle cx="40" cy="40" :r="RING_RADIUS" fill="none" stroke="#1e293b" stroke-width="7" />
              <circle cx="40" cy="40" :r="RING_RADIUS" fill="none" stroke="#22d3ee" stroke-width="7" stroke-linecap="round" :stroke-dasharray="ringDash" />
            </svg>
            <span class="absolute inset-0 flex flex-col items-center justify-center">
              <span class="text-sm" :class="ring === null ? 'text-amber-300' : 'text-slate-100'">{{ ringText }}</span>
              <span class="text-xs uppercase tracking-wider text-slate-400">{{ t('miningSite.detail.ring') }}</span>
            </span>
          </div>
        </div>

        <p v-if="lastScanLine !== null" class="text-xs text-slate-300" data-testid="companion-last-scan">{{ lastScanLine }}</p>

        <div v-if="rescanDue" class="rounded-sm border border-orange-400/40 bg-orange-500/5 px-2 py-1 text-xs text-orange-200" role="status" data-testid="companion-rescan">
          <span class="mr-2 rounded-sm border border-orange-400/50 px-1.5 py-0.5 uppercase">{{ t('miningSite.rescan.due') }}</span>
          <span v-if="site.lastScanAuthorName !== null && site.lastScanAuthorName !== undefined" class="mr-2">{{ t('miningSite.rescan.by', { author: site.lastScanAuthorName }) }}</span>
          <span class="text-orange-200/80">{{ t('miningSite.rescan.hint') }}</span>
        </div>

        <p v-if="lastScan !== null && lastScan.missingPriceTypeIds.length > 0" class="rounded-sm border border-amber-400/40 bg-amber-500/5 px-2 py-1 text-xs text-amber-300" data-testid="companion-missing-prices">{{ t('miningSite.value.missingPrices', { types: missingPriceNames }) }}</p>

        <p v-if="hasIrregularType" class="text-xs text-orange-300" data-testid="companion-irregular">
          <span class="rounded-sm border border-orange-400/40 px-1.5 py-0.5">{{ t('miningSite.badge.irregular') }}</span>
        </p>
      </template>

      <template v-if="size === 'detail' && lastScan !== null">
        <div v-if="operation !== null" class="flex items-baseline justify-between gap-2 border-t border-slate-800 pt-2">
          <span class="text-xs uppercase tracking-wider text-slate-400">{{ t('miningSite.value.remaining') }}</span>
          <span class="text-base" :class="lastScan.remainingValue === null || lastScan.remainingValue === undefined ? 'text-amber-300' : 'text-slate-100'" data-testid="companion-value">{{ valueText(lastScan.remainingValue) }}</span>
        </div>

        <div v-if="bestOre !== null" class="min-w-0 text-xs text-slate-300" data-testid="companion-best-ore">
          <span class="text-slate-500">{{ t('miningSite.bestOre.title') }}</span>
          <b class="ml-2 font-semibold text-cyan-200" :title="typeNameOf(bestOre.typeId)">{{ typeNameOf(bestOre.typeId) }}</b>
        </div>

        <div class="border-t border-slate-800 pt-2" data-testid="companion-types">
          <div class="grid grid-cols-[minmax(0,1fr)_auto_auto_auto] gap-x-3 pb-1 text-xs uppercase tracking-wider text-slate-500">
            <span>{{ t('miningSite.table.columns.type') }}</span>
            <span class="text-right">{{ t('miningSite.table.columns.remaining') }}</span>
            <span class="text-right">{{ t('miningSite.table.columns.recentEta') }}</span>
            <span class="text-right">{{ t('miningSite.table.columns.trendEta') }}</span>
          </div>
          <div
            v-for="type in lastScan.types"
            :key="type.typeId"
            :data-testid="`companion-type-row-${type.typeId}`"
            class="grid grid-cols-[minmax(0,1fr)_auto_auto_auto] items-baseline gap-x-3 border-b border-slate-800/60 py-1 text-sm"
            :class="isNotMined(type) ? 'opacity-70' : ''"
          >
            <span class="min-w-0">
              <span class="block truncate font-display font-semibold text-slate-100" :title="typeNameOf(type.typeId)">{{ typeNameOf(type.typeId) }}</span>
              <span v-if="isNotMined(type)" class="text-xs text-slate-400">{{ t('miningSite.badge.notMined') }}</span>
              <span v-if="isIrregularRate(type)" class="text-xs text-orange-300">{{ t('miningSite.badge.irregular') }}</span>
            </span>
            <span class="text-right text-slate-100">{{ remainingText(type) }}</span>
            <span class="text-right text-slate-300">{{ etaCellText(type.recentEta) }}</span>
            <span class="text-right text-slate-300">{{ etaCellText(type.trendEta) }}</span>
          </div>
        </div>
      </template>
    </template>

    <p v-else class="rounded-sm border border-slate-700 px-2 py-3 text-sm text-slate-300" data-testid="companion-empty">{{ t('miningCompanion.empty') }}</p>

    <footer v-if="showsFooter" class="mt-auto flex flex-wrap items-center gap-2 pt-1">
      <button
        v-if="site !== null"
        type="button"
        data-testid="companion-paste"
        :title="t('miningCompanion.pasteTitle')"
        class="rounded-sm border border-cyan-500/40 px-2 py-1 text-xs text-cyan-200 hover:bg-cyan-500/10"
        @click="emit('paste')"
      >{{ t('miningCompanion.paste') }}</button>
      <button
        type="button"
        data-testid="companion-desk"
        :title="t('miningCompanion.deskTitle')"
        class="rounded-sm border border-slate-600 px-2 py-1 text-xs text-slate-200 hover:bg-slate-800"
        @click="emit('open-desk')"
      >{{ t('miningCompanion.desk') }}</button>
      <button
        v-if="canLeave"
        type="button"
        data-testid="companion-leave"
        class="ml-auto rounded-sm border px-2 py-1 text-xs"
        :class="confirmingLeave ? 'border-red-500/60 bg-red-500/15 text-red-200' : 'border-slate-600 text-slate-200 hover:bg-slate-800'"
        @blur="confirmingLeave = false"
        @click="requestLeave"
      >{{ confirmingLeave ? t('miningCompanion.leaveConfirm') : t('miningCompanion.leave') }}</button>
    </footer>
  </section>
</template>
