<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import MiningModuleTabs from '@/components/mining-module/MiningModuleTabs.vue'
import ValuationSourceLabel from '@/components/mining-module/ValuationSourceLabel.vue'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import MiningCompressionBanners from '@/components/mining-operation/MiningCompressionBanners.vue'
import MiningSiteCurve from '@/components/mining-site/MiningSiteCurve.vue'
import MiningSiteScanFeed from '@/components/mining-site/MiningSiteScanFeed.vue'
import MiningSiteScanForm from '@/components/mining-site/MiningSiteScanForm.vue'
import { useCompanionDetachShortcut } from '@/composables/useCompanionDetachShortcut'
import { useMiningCompression } from '@/composables/useMiningCompression'
import { UNKNOWN_DASH, useMiningSiteFormat } from '@/composables/useMiningSiteFormat'
import { useMiningSiteOreTypes } from '@/composables/useMiningSiteOreTypes'
import { useMiningCompanionStore } from '@/stores/miningCompanion'
import { useMiningSiteStore, type MiningSiteScanType } from '@/stores/miningSite'
import { useSyncStore, type MiningOperationEvent } from '@/stores/sync'
import { formatCountdown, formatEveClock } from '@/views/MiningOperation/miningOperationTime'
import {
  COUNTING_TIERS,
  ETA_TIER_CLASSES,
  RESCAN_MINUTES_MAX,
  RESCAN_MINUTES_MIN,
  RESCAN_MINUTES_STORAGE_KEY,
  clampRescanMinutes,
  etaEvolutionOf,
  etaTierOf,
  isIrregularRate,
  isRescanDue,
  mostProfitableTypeOf,
  readRescanMinutes,
  remainingVolumeRingOf,
} from './miningSiteEstimate'

const COUNTDOWN_TICK_MS = 1000
const SITE_SCAN_RECORDED_ACTION = 'site_scan_recorded'
const PERCENT_MAX = 100
const RING_RADIUS = 34
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS
const EVOLUTION_COLUMNS = ['number', 'time', 'trend', 'recent'] as const
const TYPE_COLUMNS = ['type', 'remaining', 'recentRate', 'recentEta', 'trendRate', 'trendEta', 'value'] as const

const { t } = useI18n()
const route = useRoute()
const store = useMiningSiteStore()
const companion = useMiningCompanionStore()
const syncStore = useSyncStore()
const oreTypes = useMiningSiteOreTypes()
const { etaText, dateTimeText, wholeText, valueText, lastScanText } = useMiningSiteFormat()
const { badges: compressionBadges } = useMiningCompression(() => site.value?.compression)

const siteId = String(route.params.id)
const loading = ref(true)
const loadFailure = ref<string | null>(null)
const closeFailure = ref<string | null>(null)
const confirmingClose = ref(false)
const closing = ref(false)
const now = ref(new Date())
const rescanMinutes = ref(readRescanMinutes(localStorage))
let countdownTimer: ReturnType<typeof setInterval> | null = null

const site = computed(() => store.currentSite)
const orderedScans = computed(() => [...store.scans].sort((first, second) => first.number - second.number))
const lastScan = computed(() => orderedScans.value[orderedScans.value.length - 1] ?? null)
const isActive = computed(() => site.value?.status === 'active')
const operationId = computed(() => (loading.value ? null : (site.value?.operationId ?? null)))
const isShared = computed(() => operationId.value !== null)
const operationEvent = computed(() => (operationId.value === null ? null : syncStore.getMiningOperationEvent(operationId.value)))
const lastScanLine = computed(() => (isActive.value && site.value !== null ? lastScanText(site.value, now.value.getTime()) : null))
const tier = computed(() => (site.value === null || lastScan.value === null ? null : etaTierOf(site.value.status, lastScan.value, now.value)))
const showsCountdown = computed(() => tier.value !== null && COUNTING_TIERS.includes(tier.value))
const countdown = computed(() => {
  const eta = lastScan.value?.trendEta
  return eta === null || eta === undefined ? null : formatCountdown(new Date(eta).getTime() - now.value.getTime())
})
const trendEtaText = computed(() => (tier.value === 'depleted' ? t('miningSite.eta.depleted') : etaText(lastScan.value?.trendEta)))
const lastTypeId = computed(() => {
  const scan = lastScan.value
  if (scan === null || scan.trendEta === null || scan.trendEta === undefined) return null
  const siteEta = new Date(scan.trendEta).getTime()
  const lastType = scan.types.find(type => type.trendEta !== null && type.trendEta !== undefined && new Date(type.trendEta).getTime() === siteEta)
  return lastType === undefined ? null : lastType.typeId
})
const hasSingleScan = computed(() => isActive.value && orderedScans.value.length === 1)
const ledgerEta = computed(() => (hasSingleScan.value ? (site.value?.ledgerEta ?? null) : null))
const ledgerEtaReason = computed(() => (hasSingleScan.value ? (site.value?.ledgerEtaUnknownReason ?? null) : null))
const ring = computed(() => remainingVolumeRingOf(orderedScans.value))
const ringDash = computed(() => `${(RING_CIRCUMFERENCE * (ring.value === null ? 0 : Math.min(ring.value, PERCENT_MAX))) / PERCENT_MAX} ${RING_CIRCUMFERENCE}`)
const bestOre = computed(() => (lastScan.value === null ? null : mostProfitableTypeOf(lastScan.value)))
const evolution = computed(() => etaEvolutionOf(orderedScans.value))
const rescanDue = computed(() => site.value !== null && isRescanDue(site.value, now.value, rescanMinutes.value))
const hasIrregularType = computed(() => lastScan.value?.types.some(isIrregularRate) ?? false)
const missingPriceNames = computed(() => (lastScan.value?.missingPriceTypeIds ?? []).map(oreTypes.nameOf).join(', '))
const typeNames = computed<Record<number, string>>(() => Object.fromEntries(orderedScans.value.flatMap(scan => scan.types).map(type => [type.typeId, oreTypes.nameOf(type.typeId)])))
const firstScanText = computed(() => {
  const firstScanAt = site.value?.firstScanAt ?? orderedScans.value[0]?.scannedAt
  return firstScanAt === undefined || firstScanAt === null ? UNKNOWN_DASH : dateTimeText(firstScanAt)
})
const ringText = computed(() => (ring.value === null ? UNKNOWN_DASH : `${Math.round(ring.value)} %`))

function rateText(rate: number | null | undefined): string {
  return rate === null || rate === undefined ? UNKNOWN_DASH : wholeText(rate)
}

function etaCellText(eta: string | null | undefined): string {
  return eta === null || eta === undefined ? UNKNOWN_DASH : formatEveClock(eta)
}

function isNotMined(type: MiningSiteScanType): boolean {
  return type.etaUnknownReason === 'not_depleting'
}

function changeRescanMinutes(event: Event): void {
  const typed = Number.parseInt((event.target as HTMLInputElement).value, 10)
  if (Number.isNaN(typed)) return
  rescanMinutes.value = clampRescanMinutes(typed)
  localStorage.setItem(RESCAN_MINUTES_STORAGE_KEY, String(rescanMinutes.value))
}

async function detachCompanion(): Promise<void> {
  if (!isActive.value) return
  await companion.detach({ siteId, operationId: null })
}

useCompanionDetachShortcut(detachCompanion)

async function reloadSite(): Promise<void> {
  await store.fetchSite(siteId)
}

function isScanRecordedOnThisSite(event: MiningOperationEvent | null): boolean {
  return event !== null && event.action === SITE_SCAN_RECORDED_ACTION && event.data?.siteId === siteId
}

async function closeSite(): Promise<void> {
  closing.value = true
  closeFailure.value = null
  try {
    await store.close(siteId)
  } catch (cause) {
    closeFailure.value = cause instanceof Error ? cause.message : String(cause)
  } finally {
    closing.value = false
    confirmingClose.value = false
  }
}

watch(operationEvent, async (event) => {
  if (!isScanRecordedOnThisSite(event)) return
  await store.reloadSiteAndScans(siteId)
  loadFailure.value = store.error ?? oreTypes.error.value
})

onMounted(async () => {
  countdownTimer = setInterval(() => {
    now.value = new Date()
  }, COUNTDOWN_TICK_MS)
  await Promise.all([store.fetchSite(siteId), store.fetchScans(siteId), oreTypes.load()])
  loadFailure.value = store.error ?? oreTypes.error.value
  loading.value = false
})

onBeforeUnmount(() => {
  if (countdownTimer !== null) clearInterval(countdownTimer)
  countdownTimer = null
})
</script>

<template>
  <MainLayout>
    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
      <MiningModuleTabs active="sites" />
      <router-link :to="{ name: 'mining-sites' }" class="mb-4 inline-block text-sm text-cyan-300 hover:text-cyan-200">← {{ t('miningSite.detail.back') }}</router-link>

      <p v-if="loadFailure !== null" class="eve-card mb-4 p-4 text-sm text-red-400" role="alert">{{ loadFailure }}</p>

      <div v-if="loading" class="flex justify-center py-16">
        <LoadingSpinner size="xl" class="text-cyan-400" />
      </div>

      <div v-else-if="site !== null" class="space-y-6">
        <header class="eve-card p-5">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
              <h1 class="truncate font-display text-2xl font-bold text-slate-100" data-testid="site-label" :title="site.label">{{ site.label }}</h1>
              <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-400">
                <span class="max-w-full truncate" data-testid="site-system" :title="site.solarSystemName ?? ''">{{ site.solarSystemName ?? t('miningSite.create.systemNone') }}</span>
                <span class="font-mono text-xs" data-testid="site-scan-count">{{ t('miningSite.detail.scans', { count: orderedScans.length }) }}</span>
                <span class="font-mono text-xs"><span class="text-slate-500">{{ t('miningSite.detail.firstScan') }}</span> <span class="text-cyan-200" data-testid="site-first-scan">{{ firstScanText }}</span></span>
                <router-link
                  v-if="site.operationId"
                  :to="{ name: 'mining-operation-detail', params: { id: site.operationId } }"
                  data-testid="site-operation"
                  class="text-cyan-300 hover:text-cyan-200"
                >{{ t('miningSite.detail.operation') }}</router-link>
              </p>
            </div>
            <div class="flex items-center gap-3">
              <span
                class="rounded-sm border px-2 py-0.5 font-mono text-xs"
                :class="isActive ? 'border-cyan-500/40 text-cyan-200' : 'border-slate-600 text-slate-400'"
                data-testid="site-status"
              >{{ t(`miningSite.status.${site.status}`) }}</span>
              <button
                v-if="isActive"
                type="button"
                data-testid="site-detach"
                :title="t('miningCompanion.detachTitle')"
                class="rounded-md border border-cyan-500/40 px-3 py-1.5 text-sm text-cyan-200 hover:bg-cyan-500/10"
                @click="detachCompanion"
              >{{ t('miningCompanion.detach') }}</button>
              <button
                v-if="isActive"
                type="button"
                data-testid="site-close"
                class="rounded-md border border-slate-600 px-3 py-1.5 text-sm text-slate-200 hover:bg-slate-800"
                @click="confirmingClose = true"
              >{{ t('miningSite.close.action') }}</button>
            </div>
          </div>
          <p v-if="closeFailure !== null" class="mt-3 text-sm text-red-400" role="alert">{{ closeFailure }}</p>
        </header>

        <section class="eve-card p-5" :aria-label="t('miningSite.eta.title')">
          <div class="grid gap-6 md:grid-cols-[1fr_auto]">
            <div class="min-w-0">
              <p class="font-mono text-xs uppercase tracking-wider text-slate-400">{{ t('miningSite.eta.title') }}</p>
              <div class="mt-2 flex flex-wrap items-end gap-x-6 gap-y-2">
                <div>
                  <p class="font-mono text-xs text-slate-500">{{ t('miningSite.eta.trend') }}</p>
                  <p class="font-mono text-4xl" :class="tier === 'unknown' || lastScan === null ? 'text-amber-300' : 'text-cyan-200'" data-testid="site-eta-trend">{{ trendEtaText }}</p>
                </div>
                <div v-if="showsCountdown">
                  <p class="font-mono text-xs text-slate-500">{{ t('miningSite.eta.countdown') }}</p>
                  <p class="font-mono text-2xl text-slate-100" data-testid="site-countdown">{{ countdown }}</p>
                </div>
                <div>
                  <p class="font-mono text-xs text-slate-500">{{ t('miningSite.eta.recent') }}</p>
                  <p class="font-mono text-2xl text-slate-300" data-testid="site-eta-recent">{{ etaText(lastScan?.recentEta) }}</p>
                </div>
              </div>
              <p v-if="tier !== null" class="mt-3">
                <span class="inline-block rounded-sm border px-2 py-0.5 font-mono text-xs" :class="ETA_TIER_CLASSES[tier]" data-testid="site-tier">{{ t(`miningSite.tier.${tier}`) }}</span>
              </p>
              <p v-if="hasSingleScan" class="mt-3 text-sm text-amber-300" data-testid="site-single-scan-hint">{{ t('miningSite.single.hint') }}</p>
              <div v-if="ledgerEta !== null" class="mt-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <p class="font-mono text-2xl text-amber-300" data-testid="site-ledger-eta">{{ etaText(ledgerEta) }}</p>
                <span class="rounded-sm border border-amber-400/40 px-1.5 py-0.5 font-mono text-xs text-amber-300" data-testid="site-ledger-eta-badge">{{ t('miningSite.ledgerEta.badge') }}</span>
                <p class="w-full text-sm text-slate-400" data-testid="site-ledger-eta-note">{{ t('miningSite.ledgerEta.note') }}</p>
              </div>
              <p v-else-if="ledgerEtaReason !== null" class="mt-1 text-sm text-slate-400" data-testid="site-ledger-eta-reason">{{ t(`miningSite.ledgerEta.reason.${ledgerEtaReason}`) }}</p>
              <p v-else-if="lastTypeId !== null" class="mt-3 text-sm text-slate-300" data-testid="site-last-type">
                {{ t('miningSite.eta.lastType') }}
                <b class="font-semibold text-slate-100">{{ oreTypes.nameOf(lastTypeId) }}</b>
              </p>
              <p v-if="lastScan === null" class="mt-3 text-sm text-slate-400">{{ t('miningSite.detail.noScan') }}</p>
            </div>

            <div class="flex items-center gap-4">
              <div class="relative h-24 w-24 shrink-0" data-testid="site-ring">
                <svg viewBox="0 0 80 80" class="h-24 w-24 -rotate-90" aria-hidden="true">
                  <circle cx="40" cy="40" :r="RING_RADIUS" fill="none" stroke="#1e293b" stroke-width="7" />
                  <circle cx="40" cy="40" :r="RING_RADIUS" fill="none" stroke="#22d3ee" stroke-width="7" stroke-linecap="round" :stroke-dasharray="ringDash" />
                </svg>
                <span class="absolute inset-0 flex flex-col items-center justify-center">
                  <span class="font-mono text-lg" :class="ring === null ? 'text-amber-300' : 'text-slate-100'">{{ ringText }}</span>
                  <span class="text-xs uppercase tracking-wider text-slate-400">{{ t('miningSite.detail.ring') }}</span>
                </span>
              </div>
            </div>
          </div>

          <p v-if="lastScanLine !== null" class="mt-4 font-mono text-sm text-slate-300" data-testid="site-last-scan">{{ lastScanLine }}</p>

          <div v-if="rescanDue && site !== null" class="mt-4 rounded-sm border border-orange-400/40 bg-orange-500/5 px-3 py-2 text-sm text-orange-200" role="status" data-testid="site-rescan">
            <span class="mr-2 rounded-sm border border-orange-400/50 px-2 py-0.5 font-mono text-xs uppercase">{{ t('miningSite.rescan.due') }}</span>
            <span v-if="site.lastScanAuthorName !== null && site.lastScanAuthorName !== undefined" class="mr-2">{{ t('miningSite.rescan.by', { author: site.lastScanAuthorName }) }}</span>
            <span class="text-orange-200/80">{{ t('miningSite.rescan.hint') }}</span>
          </div>

          <label v-if="isActive" class="mt-4 flex flex-wrap items-center gap-2 text-xs text-slate-400">
            <span>{{ t('miningSite.rescan.delay') }}</span>
            <input
              name="rescanMinutes"
              type="number"
              :min="RESCAN_MINUTES_MIN"
              :max="RESCAN_MINUTES_MAX"
              :value="rescanMinutes"
              class="w-16 rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-right font-mono text-slate-100"
              @change="changeRescanMinutes"
            />
            <span>{{ t('miningSite.rescan.minutes') }}</span>
          </label>
        </section>

        <section v-if="lastScan !== null" class="grid gap-4 md:grid-cols-2">
          <div class="eve-card p-5">
            <p class="font-mono text-xs uppercase tracking-wider text-slate-400">{{ t('miningSite.value.remaining') }}</p>
            <p class="mt-1 font-mono text-2xl" :class="lastScan.remainingValue === null || lastScan.remainingValue === undefined ? 'text-amber-300' : 'text-slate-100'" data-testid="site-remaining-value">{{ valueText(lastScan.remainingValue) }}</p>
            <p class="mt-1" data-testid="site-valuation-source"><ValuationSourceLabel :source="lastScan.valuationSource ?? null" /></p>
            <p v-if="lastScan.missingPriceTypeIds.length > 0" class="mt-1 text-xs text-amber-300" data-testid="site-missing-prices">{{ t('miningSite.value.missingPrices', { types: missingPriceNames }) }}</p>
          </div>
          <div v-if="bestOre !== null" class="eve-card border-cyan-500/30 p-5" data-testid="best-ore">
            <p class="font-mono text-xs uppercase tracking-wider text-slate-400">{{ t('miningSite.bestOre.title') }}</p>
            <p class="mt-1 truncate font-display text-xl font-semibold text-cyan-200" :title="oreTypes.nameOf(bestOre.typeId)">{{ oreTypes.nameOf(bestOre.typeId) }}</p>
            <p class="mt-1 font-mono text-sm text-slate-300">
              {{ t('miningSite.eta.trend') }} {{ etaText(bestOre.trendEta) }} · {{ t('miningSite.eta.recent') }} {{ etaText(bestOre.recentEta) }}
            </p>
          </div>
        </section>

        <MiningSiteScanForm v-if="isActive" :site="site" @recorded="reloadSite" />

        <section v-if="lastScan !== null" class="eve-card p-2">
          <h2 class="px-3 pt-3 font-display text-lg font-semibold text-slate-100">{{ t('miningSite.table.title') }}</h2>
          <MiningCompressionBanners :compression="site.compression" class="px-3 pt-2" />
          <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
              <thead>
                <tr class="border-b border-slate-800 font-mono text-xs uppercase tracking-wider text-slate-400">
                  <th v-for="column in TYPE_COLUMNS" :key="column" class="px-3 py-2 font-medium" :class="column === 'type' ? '' : 'text-right'">{{ t(`miningSite.table.columns.${column}`) }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="type in lastScan.types" :key="type.typeId" :data-testid="`type-row-${type.typeId}`" class="border-b border-slate-800/60" :class="isNotMined(type) ? 'opacity-70' : ''">
                  <td class="max-w-56 px-3 py-2.5">
                    <span class="block truncate font-display font-semibold text-slate-100" :title="oreTypes.nameOf(type.typeId)">{{ oreTypes.nameOf(type.typeId) }}</span>
                    <span class="mt-1 flex flex-wrap gap-1">
                      <span v-if="compressionBadges.has(type.typeId)" data-testid="type-compression" class="rounded-sm border px-1.5 py-0.5 font-mono text-xs" :class="compressionBadges.get(type.typeId)?.classes" :title="compressionBadges.get(type.typeId)?.hint">{{ compressionBadges.get(type.typeId)?.text }}</span>
                      <span v-if="isNotMined(type)" class="rounded-sm border border-slate-600 px-1.5 py-0.5 font-mono text-xs text-slate-300">{{ t('miningSite.badge.notMined') }}</span>
                      <span v-if="type.etaUnknownReason === 'single_scan'" class="rounded-sm border border-amber-400/40 px-1.5 py-0.5 font-mono text-xs text-amber-300">{{ t('miningSite.badge.singleScan') }}</span>
                      <span v-if="isIrregularRate(type)" class="rounded-sm border border-orange-400/40 px-1.5 py-0.5 font-mono text-xs text-orange-300">{{ t('miningSite.badge.irregular') }}</span>
                      <span v-if="type.status === 'depleted' && type.depletedBetween !== null && type.depletedBetween !== undefined" class="rounded-sm border border-slate-500 px-1.5 py-0.5 font-mono text-xs text-slate-300">{{ t('miningSite.badge.depleted', { from: formatEveClock(type.depletedBetween.from), to: formatEveClock(type.depletedBetween.to) }) }}</span>
                    </span>
                  </td>
                  <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono text-slate-100">
                    {{ wholeText(type.units) }}<span v-if="type.volumeM3 !== null && type.volumeM3 !== undefined" class="block text-xs text-slate-400">{{ wholeText(type.volumeM3) }} m³</span>
                  </td>
                  <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono text-slate-300">{{ rateText(type.recentRate) }}</td>
                  <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono text-slate-300">{{ etaCellText(type.recentEta) }}</td>
                  <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono text-slate-300">{{ rateText(type.trendRate) }}</td>
                  <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono text-slate-300">{{ etaCellText(type.trendEta) }}</td>
                  <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono" :class="type.remainingValue === null || type.remainingValue === undefined ? 'text-amber-300' : 'text-slate-100'">{{ valueText(type.remainingValue) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="px-3 pb-2 pt-1 text-xs text-slate-400">{{ t('miningSite.table.hint') }}</p>
          <p v-if="hasIrregularType" class="px-3 pb-3 text-xs text-orange-300">{{ t('miningSite.irregular.hint') }}</p>
        </section>

        <MiningSiteCurve v-if="lastScan !== null" :scans="orderedScans" :type-names="typeNames" />

        <MiningSiteScanFeed v-if="lastScan !== null" :scans="orderedScans" :shared="isShared" />

        <section v-if="evolution.length > 0" class="eve-card p-2">
          <h2 class="px-3 pt-3 font-display text-lg font-semibold text-slate-100">{{ t('miningSite.evolution.title') }}</h2>
          <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
              <thead>
                <tr class="border-b border-slate-800 font-mono text-xs uppercase tracking-wider text-slate-400">
                  <th v-for="column in EVOLUTION_COLUMNS" :key="column" class="px-3 py-2 font-medium">{{ t(`miningSite.evolution.columns.${column}`) }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="announcement in evolution" :key="announcement.number" data-testid="evolution-row" :data-scan-number="announcement.number" class="border-b border-slate-800/60 font-mono">
                  <td class="px-3 py-2 text-slate-300">#{{ announcement.number }}</td>
                  <td class="px-3 py-2 text-cyan-200">{{ formatEveClock(announcement.scannedAt) }}</td>
                  <td class="px-3 py-2" :class="announcement.trendEta === null ? 'text-amber-300' : 'text-slate-100'">{{ etaText(announcement.trendEta) }}</td>
                  <td class="px-3 py-2" :class="announcement.recentEta === null ? 'text-amber-300' : 'text-slate-100'">{{ etaText(announcement.recentEta) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>

    <ConfirmModal
      :show="confirmingClose"
      :title="t('miningSite.close.confirm')"
      :subtitle="site?.label"
      :is-loading="closing"
      @confirm="closeSite"
      @cancel="confirmingClose = false"
    />
  </MainLayout>
</template>
