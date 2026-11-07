<script setup lang="ts">
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupPlanningStore } from '@/stores/group-industry/planning'
import { useSyncStore } from '@/stores/sync'
import PlanningWaitLabel from './PlanningWaitLabel.vue'
import ProjectPlanningAssignments from './ProjectPlanningAssignments.vue'
import ProjectPlanningGantt from './ProjectPlanningGantt.vue'
import UnknownContributionValue from './UnknownContributionValue.vue'

const props = defineProps<{
  projectId: string
}>()

const { t } = useI18n()
const { formatDateTime, formatDuration } = useFormatters()
const syncStore = useSyncStore()
const planningStore = useGroupPlanningStore()

const planning = computed(() => planningStore.planning)

const delaySeconds = computed((): number | null => (planning.value === null ? null : planning.value.delaySeconds))

const delayDirection = computed((): 'late' | 'early' | 'onTime' | null => {
  if (delaySeconds.value === null) return null
  if (delaySeconds.value > 0) return 'late'
  return delaySeconds.value < 0 ? 'early' : 'onTime'
})

const delayText = computed((): string => {
  if (delaySeconds.value === null || delayDirection.value === null) return ''
  return t(`groupIndustry.planning.delay.${delayDirection.value}`, { duration: formatDuration(Math.abs(delaySeconds.value)) })
})

watch(() => props.projectId, projectId => planningStore.fetchPlanning(projectId), { immediate: true })

watch(
  () => syncStore.getGroupProjectEvent(props.projectId),
  (event) => {
    if (!event) return
    planningStore.fetchPlanning(props.projectId)
  },
)

watch(
  () => syncStore.getSyncProgress('industry-project'),
  (progress) => {
    if (progress?.data?.projectId !== props.projectId) return
    planningStore.fetchPlanning(props.projectId)
  },
)
</script>

<template>
  <section class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
    <header class="px-4 py-3 border-b border-slate-800">
      <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
        {{ t('groupIndustry.planning.title') }}
      </h2>
    </header>

    <p
      v-if="planningStore.loading && !planning"
      data-testid="planning-loading"
      class="p-8 text-center text-slate-500"
    >{{ t('groupIndustry.planning.loading') }}</p>

    <div
      v-else-if="planningStore.error !== null"
      data-testid="planning-error"
      role="alert"
      class="m-4 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
    >
      <p class="font-semibold">{{ t('groupIndustry.planning.loadError') }}</p>
      <p class="mt-1 break-words">{{ planningStore.error }}</p>
    </div>

    <template v-else-if="planning">
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-px bg-slate-800 border-b border-slate-800">
        <div class="bg-slate-900 px-4 py-3">
          <p class="text-xs uppercase tracking-wider text-slate-500">{{ t('groupIndustry.planning.plannedEnd') }}</p>
          <p
            data-testid="planning-planned-end"
            :data-value="planning.plannedEnd ?? undefined"
            class="mt-1 font-display text-lg text-slate-200 tabular-nums"
          >
            <template v-if="planning.plannedEnd !== null">{{ formatDateTime(planning.plannedEnd) }}</template>
            <UnknownContributionValue v-else data-testid="planning-unknown" />
          </p>
        </div>
        <div class="bg-slate-900 px-4 py-3">
          <p class="text-xs uppercase tracking-wider text-slate-500">{{ t('groupIndustry.planning.expectedEnd') }}</p>
          <p
            data-testid="planning-expected-end"
            :data-value="planning.expectedEnd ?? undefined"
            class="mt-1 font-display text-lg text-slate-200 tabular-nums"
          >
            <template v-if="planning.expectedEnd !== null">{{ formatDateTime(planning.expectedEnd) }}</template>
            <UnknownContributionValue v-else data-testid="planning-unknown" />
          </p>
        </div>
        <div class="bg-slate-900 px-4 py-3">
          <p class="text-xs uppercase tracking-wider text-slate-500">{{ t('groupIndustry.planning.delayTitle') }}</p>
          <p
            data-testid="planning-delay"
            :data-seconds="planning.delaySeconds ?? undefined"
            :data-direction="delayDirection ?? undefined"
            class="mt-1 font-display text-lg tabular-nums"
            :class="{
              'text-amber-300': delayDirection === 'late',
              'text-emerald-400': delayDirection === 'early' || delayDirection === 'onTime',
            }"
          >
            <template v-if="delayDirection !== null">{{ delayText }}</template>
            <UnknownContributionValue v-else data-testid="planning-unknown" />
          </p>
          <PlanningWaitLabel
            v-if="planning.delayCause !== null"
            :wait="planning.delayCause"
            data-testid="planning-delay-cause"
            class="block mt-1 text-xs text-amber-300"
          />
        </div>
      </div>

      <p
        v-if="!planning.structureTimeBonusIncluded"
        data-testid="planning-structure-notice"
        class="px-4 py-2 border-b border-slate-800 text-xs text-slate-400 bg-slate-950/40"
      >{{ t('groupIndustry.planning.structureNotice') }}</p>

      <div
        v-if="planning.unscheduled.length > 0"
        data-testid="planning-unscheduled"
        class="px-4 py-3 border-b border-slate-800"
      >
        <h3 class="text-xs uppercase tracking-wider text-violet-300 mb-2">{{ t('groupIndustry.planning.unscheduledTitle') }}</h3>
        <ul class="space-y-1">
          <li
            v-for="job in planning.unscheduled"
            :key="job.lineId"
            :data-testid="`planning-unscheduled-${job.lineId}`"
            :data-reason="job.reason"
            class="flex flex-wrap items-baseline gap-x-2 text-xs min-w-0"
          >
            <UnknownContributionValue />
            <span :title="job.typeName" class="truncate max-w-[18rem] text-slate-200">{{ job.typeName }}</span>
            <span class="text-violet-300">{{ t(`groupIndustry.planning.unscheduledReason.${job.reason}`) }}</span>
          </li>
        </ul>
      </div>

      <p
        v-if="planning.characters.length === 0"
        data-testid="planning-no-characters"
        class="p-6 text-center text-slate-500"
      >{{ t('groupIndustry.planning.noCharacters') }}</p>

      <template v-else>
        <ProjectPlanningGantt :planning="planning" />

        <footer class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2 border-t border-slate-800 text-xs text-slate-400">
          <span class="inline-flex items-center gap-1.5"><i class="inline-block w-4 h-2 border border-dashed border-slate-400"></i>{{ t('groupIndustry.planning.legend.planned') }}</span>
          <span class="inline-flex items-center gap-1.5"><i class="inline-block w-4 h-2 border border-cyan-400 bg-cyan-500/60"></i>{{ t('groupIndustry.planning.legend.active') }}</span>
          <span class="inline-flex items-center gap-1.5"><i class="inline-block w-4 h-2 border border-emerald-400 bg-emerald-500/60"></i>{{ t('groupIndustry.planning.legend.done') }}</span>
          <span class="inline-flex items-center gap-1.5"><i class="inline-block w-4 h-2 border border-amber-400/40 bg-amber-400/30"></i>{{ t('groupIndustry.planning.legend.wait') }}</span>
        </footer>

        <div class="border-t border-slate-800">
          <h3 class="px-4 pt-3 font-display text-xs font-semibold uppercase tracking-widest text-cyan-400">
            {{ t('groupIndustry.planning.assignmentsTitle') }}
          </h3>
          <ProjectPlanningAssignments :characters="planning.characters" />
        </div>
      </template>
    </template>
  </section>
</template>
