<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEsiJobStatusLabel } from '@/composables/useEsiJobStatusLabel'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupContributionStore } from '@/stores/group-industry/contribution'
import { useGroupPlanningStore } from '@/stores/group-industry/planning'
import { useGroupStructureHealthStore } from '@/stores/group-industry/structureHealth'
import type { BomItem, StructureHealthRow, StructureHealthState } from '@/stores/group-industry/types'
import JobLinkModal from './JobLinkModal.vue'
import { jobsLinkableToLine } from './jobLinking'
import PlanningWaitLabel from './PlanningWaitLabel.vue'
import { facilityNamesOf, lineRentalsOfLine, planningJobsOfLine, structureRowOf, type DetailBlockKind } from './projectFlowCardDetail'
import { useFlowDetailFormat } from './useFlowDetailFormat'
import { useProjectJobActions } from './useProjectJobActions'

const props = defineProps<{
  kind: DetailBlockKind
  projectId: string
  line: BomItem
  canLinkJobs: boolean
}>()

const HEALTH_STATE_LABEL_KEYS: Record<StructureHealthState, string> = {
  ok: 'ok',
  'low-fuel': 'lowFuel',
  'offline-service': 'offlineService',
  unknown: 'unknown',
}

const { t } = useI18n()
const { formatNumber } = useFormatters()
const { esiJobStatusLabel } = useEsiJobStatusLabel()
const { detailText, iskOrUnknown, textOrUnknown, dateTimeOrUnknown } = useFlowDetailFormat()
const jobActions = useProjectJobActions()
const { linkScreenLine, availableJobs, isLoadingAvailableJobs, linkingEsiJobId, jobLinkError } = jobActions
const contributionStore = useGroupContributionStore()
const planningStore = useGroupPlanningStore()
const healthStore = useGroupStructureHealthStore()

const lineRentals = computed(() => lineRentalsOfLine(contributionStore.contributions, props.line.id))
const planning = computed(() => planningStore.planning)
const plannedJobs = computed(() => (planning.value === null ? [] : planningJobsOfLine(planning.value, props.line.id)))
const facilities = computed(() => facilityNamesOf(props.line).map(name => ({ name, health: structureRowOf(healthStore.rows, name) })))

function healthLabel(row: StructureHealthRow): string {
  return t(`groupIndustry.structureHealth.state.${HEALTH_STATE_LABEL_KEYS[row.state]}`)
}

function fuelLabel(row: StructureHealthRow): string {
  return row.fuelDays === null ? detailText('unknown') : t('groupIndustry.structureHealth.fuel', { days: row.fuelDays })
}
</script>

<template>
  <div v-if="kind === 'jobs'">
    <ul v-if="line.jobMatches.length > 0" class="space-y-0.5">
      <li v-for="match in line.jobMatches" :key="match.id" data-testid="flow-detail-job" class="flex flex-wrap gap-x-2">
        <span>{{ textOrUnknown(match.characterName) }}</span>
        <span class="tabular-nums">{{ detailText('phrases.runs', { runs: formatNumber(match.runs, 0) }) }}</span>
        <span>{{ esiJobStatusLabel(match.status) }}</span>
        <span class="text-slate-500">{{ textOrUnknown(match.facilityName) }}</span>
        <span v-if="match.manual" class="text-slate-500">{{ detailText('phrases.manualLink') }}</span>
      </li>
    </ul>
    <p v-else class="text-slate-400">{{ detailText('phrases.noJob') }}</p>
    <button
      v-if="canLinkJobs"
      type="button"
      data-testid="flow-detail-link-job"
      class="mt-1.5 border border-slate-600 px-2 py-0.5 text-[11px] text-slate-200 hover:border-cyan-400 hover:text-cyan-300"
      @click="jobActions.openLinkScreen(projectId, line)"
    >{{ detailText('linkJob') }}</button>
    <p v-if="jobLinkError !== null && linkScreenLine === null" role="alert" class="mt-1 text-red-300">{{ jobLinkError }}</p>
    <JobLinkModal
      v-if="canLinkJobs"
      :show="linkScreenLine !== null"
      :line-name="linkScreenLine?.typeName ?? ''"
      :jobs="jobsLinkableToLine(availableJobs, linkScreenLine?.id ?? null)"
      :loading="isLoadingAvailableJobs"
      :error="jobLinkError"
      :linking-esi-job-id="linkingEsiJobId"
      @close="jobActions.closeLinkScreen"
      @link="(esiJobId, runs) => jobActions.linkJob(projectId, esiJobId, runs)"
    />
  </div>

  <div v-else-if="kind === 'reservation'">
    <ul v-if="line.reservations.length > 0" class="space-y-0.5">
      <li v-for="reservation in line.reservations" :key="reservation.participantId" data-testid="flow-detail-reservation" class="flex flex-wrap gap-x-2">
        <span>{{ textOrUnknown(reservation.characterName) }}</span>
        <span v-if="reservation.isMine" class="text-cyan-300">{{ detailText('phrases.mine') }}</span>
        <span class="tabular-nums">{{ formatNumber(reservation.quantity, 0) }}</span>
        <span class="text-slate-500">{{ detailText('phrases.expiresAt', { date: dateTimeOrUnknown(reservation.expiresAt) }) }}</span>
      </li>
    </ul>
    <p v-else class="text-slate-400">{{ detailText('phrases.noReservation') }}</p>
    <ul v-if="lineRentals.length > 0" class="mt-1 space-y-0.5">
      <li v-for="rental in lineRentals" :key="rental.id" data-testid="flow-detail-rental" class="flex flex-wrap gap-x-2">
        <span>{{ textOrUnknown(rental.memberCharacterName) }}</span>
        <span>{{ detailText('phrases.lineRental') }}</span>
        <span class="tabular-nums">{{ formatNumber(rental.quantity, 0) }}</span>
        <span class="tabular-nums">{{ iskOrUnknown(rental.estimatedValue) }}</span>
      </li>
    </ul>
  </div>

  <div v-else-if="kind === 'planning'">
    <p v-if="planning === null" class="text-slate-400">{{ detailText('unknown') }}</p>
    <p v-else-if="!planning.lanesShown" class="text-slate-400">{{ detailText('phrases.lanesHidden') }}</p>
    <p v-else-if="plannedJobs.length === 0" class="text-slate-400">{{ detailText('phrases.notPlanned') }}</p>
    <ul v-else class="space-y-1">
      <li v-for="job in plannedJobs" :key="job.lineId + job.activity" data-testid="flow-detail-planned-job">
        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
          <dt class="text-slate-500">{{ detailText('fields.plannedStart') }}</dt>
          <dd>{{ dateTimeOrUnknown(job.planned?.start) }}</dd>
          <dt class="text-slate-500">{{ detailText('fields.plannedEnd') }}</dt>
          <dd>{{ dateTimeOrUnknown(job.planned?.end) }}</dd>
          <template v-if="job.real !== null">
            <dt class="text-slate-500">{{ detailText('fields.realEnd') }}</dt>
            <dd>{{ dateTimeOrUnknown(job.real.end) }}</dd>
          </template>
          <template v-if="job.wait !== null">
            <dt class="text-slate-500">{{ detailText('fields.wait') }}</dt>
            <dd><PlanningWaitLabel :wait="job.wait" /></dd>
          </template>
        </dl>
      </li>
    </ul>
  </div>

  <div v-else-if="kind === 'structure'">
    <p v-if="facilities.length === 0" class="text-slate-400">{{ detailText('phrases.noStructure') }}</p>
    <ul v-else class="space-y-0.5">
      <li v-for="facility in facilities" :key="facility.name" data-testid="flow-detail-structure" class="flex flex-wrap gap-x-2">
        <span>{{ facility.name }}</span>
        <template v-if="facility.health !== undefined">
          <span>{{ healthLabel(facility.health) }}</span>
          <span class="tabular-nums text-slate-400">{{ fuelLabel(facility.health) }}</span>
          <span v-if="facility.health.offlineServices.length > 0" class="text-red-300">{{ t('groupIndustry.structureHealth.offlineServices', { services: facility.health.offlineServices.join(', ') }) }}</span>
        </template>
        <span v-else class="text-slate-500">{{ detailText('unknown') }}</span>
      </li>
    </ul>
  </div>
</template>
