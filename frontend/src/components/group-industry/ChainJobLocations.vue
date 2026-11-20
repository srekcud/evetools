<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { JobLocation, JobLocations } from '@/stores/group-industry/types'
import JobLocationBadge from './JobLocationBadge.vue'
import { jobInputOutside, jobLocationUnknownReasons } from './jobLocationCheck'

const props = defineProps<{
  locations: JobLocations
}>()

const { t } = useI18n()

const reasons = computed(() => jobLocationUnknownReasons(props.locations))
const inputOutside = computed(() => jobInputOutside(props.locations))

function sideName(side: JobLocation): string {
  return side.name ?? t('groupIndustry.jobLocations.unknownLocation')
}
</script>

<template>
  <div data-testid="chain-job-io" class="text-xs space-y-0.5 py-0.5">
    <div class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
      <span data-testid="chain-job-input" :data-status="locations.blueprint.status" class="text-slate-300">
        {{ sideName(locations.blueprint) }}
        <span v-if="locations.blueprint.status === 'inside'" class="text-emerald-400">· {{ t('groupIndustry.jobLocations.projectContainer') }}</span>
      </span>
      <span class="text-slate-500" aria-hidden="true">&rarr;</span>
      <span data-testid="chain-job-output" :data-status="locations.output.status" class="text-slate-300">
        {{ sideName(locations.output) }}
        <span v-if="locations.output.status === 'inside'" class="text-emerald-400">· {{ t('groupIndustry.jobLocations.projectContainer') }}</span>
      </span>
      <JobLocationBadge :locations="locations" data-testid="chain-job-check" />
    </div>
    <p v-if="inputOutside" data-testid="chain-job-input-outside" class="text-amber-400 whitespace-normal">{{ t('groupIndustry.jobLocations.inputOutside') }}</p>
    <p v-for="reason in reasons" :key="reason" data-testid="chain-job-io-reason" class="text-violet-300 whitespace-normal">{{ t(`groupIndustry.jobLocations.reasons.${reason}`) }}</p>
  </div>
</template>
