<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { jobLocationCheck, jobLocationUnknownReasons, type JobLocationCheck } from './jobLocationCheck'
import type { JobLocations } from '@/stores/group-industry/types'

const props = defineProps<{
  locations: JobLocations
}>()

const { t } = useI18n()

const CHECK_TEXT_KEYS: Record<JobLocationCheck, string> = {
  ok: 'ok',
  'blueprint-outside': 'blueprintOutside',
  'output-outside': 'outputOutside',
  unknown: 'unknown',
}

const CHECK_CLASSES: Record<JobLocationCheck, string> = {
  ok: 'bg-emerald-500/[0.12] text-emerald-400 border-emerald-500/25',
  'blueprint-outside': 'bg-amber-500/[0.12] text-amber-400 border-amber-500/25',
  'output-outside': 'bg-amber-500/[0.12] text-amber-400 border-amber-500/25',
  unknown: 'bg-slate-500/[0.12] text-slate-400 border-slate-500/25',
}

const check = computed(() => jobLocationCheck(props.locations))
const reasonTexts = computed(() => jobLocationUnknownReasons(props.locations).map(reason => t(`groupIndustry.jobLocations.reasons.${reason}`)))
</script>

<template>
  <span
    :data-check="check"
    :title="reasonTexts.join(' · ')"
    class="inline-flex items-center text-xs px-2 py-0.5 rounded border font-medium"
    :class="CHECK_CLASSES[check]"
  >{{ t(`groupIndustry.jobLocations.check.${CHECK_TEXT_KEYS[check]}`) }}</span>
</template>
