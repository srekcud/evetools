<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMiningSiteFormat } from '@/composables/useMiningSiteFormat'
import type { MiningSiteScan } from '@/stores/miningSite'
import { formatEveClock } from '@/views/MiningOperation/miningOperationTime'

const FEED_SCAN_COUNT = 5

const props = defineProps<{ scans: MiningSiteScan[]; shared?: boolean }>()

const { t } = useI18n()
const { etaText, authorText } = useMiningSiteFormat()

const recentScans = computed(() => [...props.scans].sort((first, second) => second.number - first.number).slice(0, FEED_SCAN_COUNT))

function isUnknownAuthor(scan: MiningSiteScan): boolean {
  return scan.authorName === null || scan.authorName === undefined
}
</script>

<template>
  <section class="eve-card p-5" :aria-label="t('miningSite.feed.title')" data-testid="scan-feed">
    <h2 class="font-display text-lg font-semibold text-slate-100">{{ t('miningSite.feed.title') }}</h2>
    <p v-if="shared" class="mt-1 text-xs text-cyan-300">{{ t('miningSite.feed.shared') }}</p>
    <ul class="mt-3 divide-y divide-slate-800/70">
      <li
        v-for="scan in recentScans"
        :key="scan.id"
        class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2 font-mono text-sm"
        data-testid="scan-feed-row"
        :data-scan-number="scan.number"
      >
        <span class="text-slate-300">#{{ scan.number }}</span>
        <span class="text-cyan-200">{{ formatEveClock(scan.scannedAt) }}</span>
        <span class="min-w-0 max-w-full flex-1 truncate font-sans" :class="isUnknownAuthor(scan) ? 'text-amber-300' : 'text-slate-100'" :title="authorText(scan.authorName)">{{ authorText(scan.authorName) }}</span>
        <span class="text-slate-400">{{ t('miningSite.feed.types', { count: scan.typeCount }, scan.typeCount) }}</span>
        <span :class="scan.trendEta === null || scan.trendEta === undefined ? 'text-amber-300' : 'text-slate-100'">{{ etaText(scan.trendEta) }}</span>
      </li>
    </ul>
  </section>
</template>
