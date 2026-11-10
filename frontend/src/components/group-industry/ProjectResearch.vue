<script setup lang="ts">
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupResearchStore } from '@/stores/group-industry/research'
import type { ResearchDuration, ResearchRow } from '@/stores/group-industry/types'
import { useSyncStore } from '@/stores/sync'
import UnknownContributionValue from './UnknownContributionValue.vue'

const props = defineProps<{
  projectId: string
}>()

const { t, te } = useI18n()
const { formatIsk, formatDuration } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()
const syncStore = useSyncStore()
const researchStore = useGroupResearchStore()

const proposedRows = computed(() => researchStore.rows.filter(row => row.verdict !== 'not_worth'))
const notWorthCount = computed(() => researchStore.rows.length - proposedRows.value.length)

function typeLabel(row: ResearchRow): string {
  return row.typeName ?? `#${row.typeId}`
}

function reasonLabel(reason: string): string {
  const key = `groupIndustry.research.reason.${reason}`
  return te(key) ? t(key) : reason
}

function unknownReasons(row: ResearchRow): string[] {
  return [...new Set(row.missingData.map(missing => reasonLabel(missing.reason)))]
}

function durationReasons(duration: ResearchDuration): string {
  return [...new Set(duration.missingData.map(missing => reasonLabel(missing.reason)))].join(', ')
}

watch(() => props.projectId, projectId => researchStore.fetchResearch(projectId), { immediate: true })

watch(
  () => syncStore.getGroupProjectEvent(props.projectId),
  (event) => {
    if (!event) return
    researchStore.fetchResearch(props.projectId)
  },
)

watch(
  () => syncStore.getSyncProgress('industry-project'),
  (progress) => {
    if (progress?.data?.projectId !== props.projectId) return
    researchStore.fetchResearch(props.projectId)
  },
)
</script>

<template>
  <section
    v-if="researchStore.loading || researchStore.error !== null || researchStore.rows.length > 0"
    data-testid="research"
    class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden"
  >
    <header class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-slate-800">
      <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
        {{ t('groupIndustry.research.title') }}
      </h2>
      <span
        data-testid="research-in-game-notice"
        class="px-2 py-1 rounded border border-amber-500/30 text-xs text-amber-400"
      >{{ t('groupIndustry.research.inGameNotice') }}</span>
    </header>

    <p
      v-if="researchStore.loading && researchStore.rows.length === 0"
      data-testid="research-loading"
      class="p-8 text-center text-slate-500"
    >{{ t('groupIndustry.research.loading') }}</p>

    <div
      v-else-if="researchStore.error !== null"
      data-testid="research-error"
      role="alert"
      class="m-4 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
    >
      <p class="font-semibold">{{ t('groupIndustry.research.loadError') }}</p>
      <p class="mt-1 break-words">{{ researchStore.error }}</p>
    </div>

    <template v-else>
      <ul v-if="proposedRows.length > 0" class="divide-y divide-slate-800/60">
        <li
          v-for="row in proposedRows"
          :key="row.typeId"
          :data-testid="`research-row-${row.typeId}`"
          class="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 hover:bg-slate-800/30"
        >
          <div class="flex items-center gap-2 min-w-0 flex-1 basis-56">
            <img
              :src="getTypeIconUrl(row.typeId, 32)"
              alt=""
              class="w-8 h-8 rounded border border-slate-700 bg-slate-800 flex-shrink-0"
              @error="onImageError"
            />
            <div class="min-w-0">
              <span class="block truncate font-medium text-slate-100" :title="typeLabel(row)">{{ typeLabel(row) }}</span>
              <span
                :data-testid="`research-row-${row.typeId}-levels`"
                class="block text-xs text-slate-400"
              >{{ t('groupIndustry.research.levels', { current: row.currentLevel, target: row.targetLevel, runs: row.runs }) }}</span>
            </div>
          </div>

          <div class="text-right font-mono tabular-nums text-sm">
            <span class="block text-xs font-sans uppercase tracking-wider text-slate-500">{{ t('groupIndustry.research.columns.saving') }}</span>
            <span :data-testid="`research-row-${row.typeId}-saving`" class="text-slate-300">
              <UnknownContributionValue v-if="row.materialSaving === null" />
              <template v-else>{{ formatIsk(row.materialSaving) }}</template>
            </span>
          </div>

          <div class="text-right font-mono tabular-nums text-sm">
            <span class="block text-xs font-sans uppercase tracking-wider text-slate-500">{{ t('groupIndustry.research.columns.cost') }}</span>
            <span :data-testid="`research-row-${row.typeId}-cost`" class="text-slate-300">
              <UnknownContributionValue v-if="row.researchCost === null" />
              <template v-else>{{ formatIsk(row.researchCost) }}</template>
            </span>
          </div>

          <div class="text-right font-mono tabular-nums text-sm">
            <span class="block text-xs font-sans uppercase tracking-wider text-slate-500">{{ t('groupIndustry.research.columns.net') }}</span>
            <span :data-testid="`research-row-${row.typeId}-net`" class="font-semibold text-emerald-300">
              <UnknownContributionValue v-if="row.net === null" />
              <template v-else>{{ formatIsk(row.net) }}</template>
            </span>
          </div>

          <div class="basis-full flex flex-wrap gap-x-6 gap-y-1 text-xs">
            <span :data-testid="`research-row-${row.typeId}-me-duration`" class="text-slate-400">
              <span class="uppercase tracking-wider text-slate-500">{{ t('groupIndustry.research.duration.me', { level: row.meResearchDuration.targetLevel }) }}</span>
              <span v-if="row.meResearchDuration.seconds !== null" class="ml-2 font-mono tabular-nums text-cyan-300">{{ formatDuration(row.meResearchDuration.seconds) }}</span>
              <template v-else>
                <UnknownContributionValue class="ml-2" />
                <span class="ml-1 text-violet-300">{{ durationReasons(row.meResearchDuration) }}</span>
              </template>
            </span>
            <span
              v-if="row.teResearchDuration !== null"
              :data-testid="`research-row-${row.typeId}-te-duration`"
              class="text-slate-400"
            >
              <span class="uppercase tracking-wider text-slate-500">{{ t('groupIndustry.research.duration.te', { level: row.teResearchDuration.targetLevel }) }}</span>
              <span v-if="row.teResearchDuration.seconds !== null" class="ml-2 font-mono tabular-nums text-cyan-300">{{ formatDuration(row.teResearchDuration.seconds) }}</span>
              <template v-else>
                <UnknownContributionValue class="ml-2" />
                <span class="ml-1 text-violet-300">{{ durationReasons(row.teResearchDuration) }}</span>
              </template>
            </span>
          </div>

          <p
            v-if="row.verdict === 'unknown'"
            :data-testid="`research-row-${row.typeId}-unknown`"
            class="basis-full text-xs text-violet-300"
          >{{ t('groupIndustry.research.unknown', { reasons: unknownReasons(row).join(', ') }) }}</p>
        </li>
      </ul>

      <p
        v-if="proposedRows.length > 0"
        data-testid="research-duration-notice"
        class="px-4 py-3 border-t border-slate-800 text-xs text-slate-500"
      >{{ t('groupIndustry.research.durationNotice') }}</p>

      <p
        v-if="notWorthCount > 0"
        data-testid="research-not-worth"
        class="px-4 py-3 border-t border-slate-800 text-xs text-slate-500"
      >{{ t('groupIndustry.research.notWorth', { count: notWorthCount }, notWorthCount) }}</p>
    </template>
  </section>
</template>
