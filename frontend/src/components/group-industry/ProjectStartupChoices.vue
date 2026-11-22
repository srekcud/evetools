<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { apiRequest } from '@/services/api'
import type { ProjectWorkStations, SourcingDecisions, WorkStationType } from '@/stores/group-industry/types'
import { ACTIVITY_STYLES } from './companionActivityStyles'
import { canEditStations, sourcingChoiceSummary, stationChoiceLines } from './projectStartupChoices'

const props = defineProps<{ projectId: string; status: string; canEdit: boolean }>()

const { t } = useI18n()
const { formatIsk } = useFormatters()

const workStations = ref<ProjectWorkStations | null>(null)
const decisions = ref<SourcingDecisions | null>(null)
const stationsError = ref<string | null>(null)
const sourcingError = ref<string | null>(null)
let latestLoad = 0

const lines = computed(() => (workStations.value === null ? [] : stationChoiceLines(workStations.value)))
const sourcing = computed(() => (decisions.value === null ? null : sourcingChoiceSummary(decisions.value.rows)))
const canEditNow = computed(() => props.canEdit && props.status !== 'completed' && workStations.value !== null && canEditStations(workStations.value))

function typeOf(workType: string): WorkStationType | undefined {
  return workStations.value?.workTypes.find(type => type.workType === workType)
}

function gapText(gap: number | null): string {
  if (gap === null) return t('groupIndustry.startupChoices.gapUnknown')
  return gap > 0 ? `+ ${formatIsk(gap)}` : formatIsk(gap)
}

async function load(): Promise<void> {
  const load = ++latestLoad
  const projectPath = `/industry/projects/${props.projectId}`
  stationsError.value = null
  sourcingError.value = null
  const [stationsResult, sourcingResult] = await Promise.allSettled([
    apiRequest<ProjectWorkStations>(`${projectPath}/work-stations`),
    apiRequest<SourcingDecisions>(`${projectPath}/sourcing-decisions`),
  ])
  if (load !== latestLoad) return
  if (stationsResult.status === 'fulfilled' && Array.isArray(stationsResult.value?.workTypes)) {
    workStations.value = stationsResult.value
  } else if (stationsResult.status === 'fulfilled') {
    workStations.value = null
    stationsError.value = t('groupIndustry.startupChoices.unexpectedAnswer')
  } else {
    workStations.value = null
    stationsError.value = stationsResult.reason instanceof Error ? stationsResult.reason.message : t('groupIndustry.startupChoices.loadFailed')
  }
  if (sourcingResult.status === 'fulfilled' && Array.isArray(sourcingResult.value?.rows)) {
    decisions.value = sourcingResult.value
  } else if (sourcingResult.status === 'fulfilled') {
    decisions.value = null
    sourcingError.value = t('groupIndustry.startupChoices.unexpectedAnswer')
  } else {
    decisions.value = null
    sourcingError.value = sourcingResult.reason instanceof Error ? sourcingResult.reason.message : t('groupIndustry.startupChoices.loadFailed')
  }
}

watch(() => [props.projectId, props.status], load, { immediate: true })
</script>

<template>
  <section data-testid="startup-choices" class="mb-4 rounded-lg border border-slate-700 bg-slate-900/50 p-3">
    <div class="mb-2 flex flex-wrap items-center gap-2">
      <h3 class="font-display text-xs font-semibold uppercase tracking-widest text-cyan-400">
        {{ t('groupIndustry.startupChoices.title') }}
      </h3>
      <span class="flex-1" />
      <router-link
        v-if="canEditNow"
        data-testid="startup-edit-stations"
        :to="{ name: 'industry-project-setup', params: { id: projectId }, query: { edit: 'stations' } }"
        class="rounded-lg border border-cyan-500/40 px-3 py-1 text-xs text-cyan-300 transition-colors hover:bg-cyan-500/10"
      >
        {{ t('groupIndustry.startupChoices.editStations') }}
      </router-link>
    </div>

    <p v-if="stationsError" data-testid="startup-choices-error" class="text-sm text-red-300">
      {{ t('groupIndustry.startupChoices.stationsFailed', { message: stationsError }) }}
    </p>
    <ul v-else class="space-y-1 text-sm">
      <li
        v-for="line in lines"
        :key="line.workType"
        class="flex flex-wrap items-baseline gap-x-2 border-l-2 pl-2"
        :class="ACTIVITY_STYLES[typeOf(line.workType)?.activity ?? 'unknown'].stripe"
      >
        <span class="text-slate-400">{{ t(`groupIndustry.setup.stations.workTypes.${line.workType}`) }}</span>
        <span :data-testid="`startup-station-${line.workType}`" class="text-slate-100">
          {{ line.stationName ?? t('groupIndustry.setup.stations.unknownStation') }}
        </span>
        <template v-if="line.differs">
          <span :data-testid="`startup-station-recommended-${line.workType}`" class="text-amber-300">
            {{ t('groupIndustry.startupChoices.recommended', { station: line.recommendedStationName ?? t('groupIndustry.setup.stations.unknownStation') }) }}
          </span>
          <span :data-testid="`startup-station-gap-${line.workType}`" class="text-amber-300">
            {{ t('groupIndustry.startupChoices.gap', { amount: gapText(line.gap) }) }}
          </span>
        </template>
      </li>
    </ul>

    <p v-if="sourcingError" data-testid="startup-sourcing-error" class="mt-2 text-sm text-red-300">
      {{ t('groupIndustry.startupChoices.sourcingFailed', { message: sourcingError }) }}
    </p>
    <div v-else-if="sourcing" class="mt-2 text-sm text-slate-300">
      <span data-testid="startup-sourcing-counts">
        {{ t('groupIndustry.startupChoices.sourcingCounts', { build: sourcing.build, jita: sourcing.jita, structure: sourcing.structure }) }}
      </span>
      <span v-if="sourcing.offRecommended > 0" data-testid="startup-sourcing-off" class="ml-2 text-amber-300">
        {{ t('groupIndustry.startupChoices.sourcingOff', { count: sourcing.offRecommended, amount: gapText(sourcing.gap) }) }}
      </span>
      <span v-else data-testid="startup-sourcing-on-recommended" class="ml-2 text-emerald-300">
        {{ t('groupIndustry.startupChoices.sourcingOnRecommended') }}
      </span>
    </div>
  </section>
</template>
