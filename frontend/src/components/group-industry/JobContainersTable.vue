<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import JobLocationBadge from './JobLocationBadge.vue'
import { jobLocationUnknownReasons } from './jobLocationCheck'
import type { JobLocation, JobLocations, JobMatch } from '@/stores/group-industry/types'

type JobContainerRow = {
  id: string
  productName: string
  characterName: string | null
  runs: number
  locations: JobLocations
}

const { t } = useI18n()
const projectStore = useGroupProjectStore()

const rows = computed<JobContainerRow[]>(() =>
  projectStore.bomItems.flatMap(line =>
    (line.jobMatches ?? [])
      .filter((match: JobMatch): match is JobMatch & { locations: JobLocations } => match.locations !== null && match.locations !== undefined)
      .map(match => ({
        id: match.id,
        productName: line.typeName,
        characterName: match.characterName,
        runs: match.runs,
        locations: match.locations,
      })),
  ),
)

function sideName(side: JobLocation): string {
  return side.name ?? t('groupIndustry.jobLocations.unknownLocation')
}

function sideClass(side: JobLocation): string {
  if (side.status === 'outside') return 'text-amber-400'
  if (side.status === 'unknown') return 'text-slate-500'
  return 'text-slate-200'
}

function reasonText(locations: JobLocations): string {
  return jobLocationUnknownReasons(locations).map(reason => t(`groupIndustry.jobLocations.reasons.${reason}`)).join(' · ')
}
</script>

<template>
  <div v-if="rows.length > 0" class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden mt-6">
    <div class="px-6 py-3 border-b border-slate-800">
      <h3 class="text-sm font-semibold text-slate-200">{{ t('groupIndustry.jobLocations.title') }}</h3>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-800">
            <th class="text-left py-2.5 px-6">{{ t('groupIndustry.jobs.product') }}</th>
            <th class="text-left py-2.5 px-3">{{ t('groupIndustry.jobs.character') }}</th>
            <th class="text-left py-2.5 px-3">{{ t('groupIndustry.jobLocations.blueprint') }}</th>
            <th class="text-left py-2.5 px-3">{{ t('groupIndustry.jobLocations.output') }}</th>
            <th class="text-left py-2.5 px-6">{{ t('groupIndustry.jobLocations.control') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/30">
          <tr v-for="row in rows" :key="row.id" data-testid="job-container-row" class="hover:bg-slate-800/30">
            <td class="py-2.5 px-6 text-slate-200">{{ row.productName }} <span class="text-xs text-slate-500">&times; {{ row.runs }}</span></td>
            <td class="py-2.5 px-3 text-slate-400">{{ row.characterName }}</td>
            <td class="py-2.5 px-3" :class="sideClass(row.locations.blueprint)">
              {{ sideName(row.locations.blueprint) }}
              <span v-if="row.locations.blueprint.divisionName !== null" class="text-xs text-slate-500">{{ row.locations.blueprint.divisionName }}</span>
            </td>
            <td class="py-2.5 px-3" :class="sideClass(row.locations.output)">
              {{ sideName(row.locations.output) }}
              <span v-if="row.locations.output.divisionName !== null" class="text-xs text-slate-500">{{ row.locations.output.divisionName }}</span>
            </td>
            <td class="py-2.5 px-6">
              <JobLocationBadge :locations="row.locations" data-testid="job-container-check" />
              <span v-if="reasonText(row.locations) !== ''" data-testid="job-container-reason" class="ml-2 text-xs text-slate-500">{{ reasonText(row.locations) }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
