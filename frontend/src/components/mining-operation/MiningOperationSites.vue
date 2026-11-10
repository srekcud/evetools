<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import MiningCompressionBanners from '@/components/mining-operation/MiningCompressionBanners.vue'
import MiningSiteCreateModal from '@/components/mining-site/MiningSiteCreateModal.vue'
import { UNKNOWN_DASH, useMiningSiteFormat } from '@/composables/useMiningSiteFormat'
import type { MiningCompression, MiningCompressorKind } from '@/stores/miningOperation'
import type { MiningSite } from '@/stores/miningSite'

const ISK_UNIT = 'ISK'
const MIN_SCANS_FOR_EXTRACTED_VALUE = 2

const props = defineProps<{
  operationId: string
  sites: MiningSite[]
  failure: string | null
  canCreate: boolean
  now: number
}>()

const { t } = useI18n()
const { lastScanText, dateTimeText, valueText } = useMiningSiteFormat()

const creating = ref(false)
const closingActive = ref(false)

const hasActiveSite = computed(() => props.sites.some(site => site.status === 'active'))

function openCreate(closesActive: boolean): void {
  closingActive.value = closesActive
  creating.value = true
}

function endText(site: MiningSite): string {
  if (site.status === 'active') return t('miningOperationSites.endOngoing')
  if (site.closedAt === null || site.closedAt === undefined) return t('miningOperationSites.endUnknown')
  return dateTimeText(site.closedAt)
}

function extractedText(site: MiningSite): string {
  if (site.extractedValue === null || site.extractedValue === undefined) return t('miningOperationSites.extractedUnknown')
  return `${valueText(site.extractedValue)} ${ISK_UNIT}`
}

function showsExtractedHint(site: MiningSite): boolean {
  return site.scanCount === undefined || site.scanCount < MIN_SCANS_FOR_EXTRACTED_VALUE
}

function distinctKinds(lists: MiningCompressorKind[][]): MiningCompressorKind[] {
  return [...new Set(lists.flat())]
}

const sitesCompression = computed<MiningCompression | null>(() => {
  const boosted = props.sites
    .map(site => site.compression)
    .filter((compression): compression is MiningCompression => compression !== null && compression !== undefined && compression.boostShipTypeId !== null && compression.boostShipTypeId !== undefined)
  const [first] = boosted
  if (first === undefined) return null
  return {
    ...first,
    types: [],
    gaps: {
      noHull: distinctKinds(boosted.map(compression => compression.gaps.noHull)),
      notMounted: distinctKinds(boosted.map(compression => compression.gaps.notMounted)),
    },
  }
})
</script>

<template>
  <section class="eve-card p-5" data-testid="operation-sites" :aria-label="t('miningOperationSites.title')">
    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
      <h2 class="font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperationSites.title') }}</h2>
      <div v-if="canCreate" class="flex flex-wrap gap-2">
        <button
          v-if="hasActiveSite"
          type="button"
          data-testid="operation-site-next"
          class="rounded-md border border-cyan-500/60 px-3 py-1.5 text-sm font-medium text-cyan-200 hover:bg-cyan-500/10"
          @click="openCreate(true)"
        >{{ t('miningOperationSites.next') }}</button>
        <button
          type="button"
          data-testid="operation-site-create"
          class="rounded-md bg-cyan-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-cyan-500"
          @click="openCreate(false)"
        >{{ t('miningOperationSites.create') }}</button>
      </div>
    </div>

    <p v-if="failure !== null" class="mb-3 text-sm text-red-400" role="alert">{{ failure }}</p>

    <MiningCompressionBanners :compression="sitesCompression" />

    <p v-if="sites.length === 0" class="text-sm text-slate-400" data-testid="operation-sites-empty">{{ t('miningOperationSites.empty') }}</p>
    <ul v-else class="grid gap-3 sm:grid-cols-2">
      <li v-for="site in sites" :key="site.id" class="min-w-0">
        <RouterLink
          :to="{ name: 'mining-site-detail', params: { id: site.id } }"
          class="block h-full rounded-sm border border-slate-700 bg-slate-900/60 p-3 hover:border-cyan-500/50"
          data-testid="operation-site-card"
        >
          <span class="flex items-start justify-between gap-2">
            <span class="min-w-0">
              <span class="block truncate font-display font-semibold text-slate-100" :title="site.label">{{ site.label }}</span>
              <span v-if="site.solarSystemName !== null && site.solarSystemName !== undefined" class="mt-1 block truncate text-sm text-slate-400" :title="site.solarSystemName">{{ site.solarSystemName }}</span>
            </span>
            <span
              class="shrink-0 rounded-sm border px-2 py-0.5 font-mono text-xs"
              :class="site.status === 'active' ? 'border-cyan-500/40 text-cyan-200' : 'border-slate-600 text-slate-400'"
            >{{ t(`miningSite.status.${site.status}`) }}</span>
          </span>
          <span class="mt-2 block font-mono text-xs text-slate-300">{{ lastScanText(site, now) ?? t('miningOperationSites.noScan') }}</span>
          <span class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 font-mono text-xs text-slate-300">
            <span class="min-w-0 truncate" data-testid="operation-site-start" :title="dateTimeText(site.createdAt)"><span class="text-slate-500">{{ t('miningOperationSites.start') }}</span> {{ dateTimeText(site.createdAt) }}</span>
            <span class="min-w-0 truncate" data-testid="operation-site-end" :title="endText(site)"><span class="text-slate-500">{{ t('miningOperationSites.end') }}</span> {{ endText(site) }}</span>
            <span class="min-w-0 truncate" data-testid="operation-site-scans"><span class="text-slate-500">{{ t('miningOperationSites.scans') }}</span> {{ site.scanCount ?? UNKNOWN_DASH }}</span>
            <span class="col-span-2 min-w-0 break-words" data-testid="operation-site-extracted"><span class="text-slate-500">{{ t('miningOperationSites.extracted') }}</span> {{ extractedText(site) }}<span v-if="showsExtractedHint(site)" class="text-slate-500"> ({{ t('miningOperationSites.extractedHint') }})</span></span>
          </span>
        </RouterLink>
      </li>
    </ul>

    <MiningSiteCreateModal :show="creating" :operation-id="operationId" :closes-active="closingActive" @close="creating = false" />
  </section>
</template>
