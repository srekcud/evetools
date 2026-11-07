<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { PlanningWait } from '@/stores/group-industry/types'

const props = defineProps<{
  wait: PlanningWait
}>()

const { t } = useI18n()
const { formatDuration } = useFormatters()

const WAIT_MESSAGE_KEYS = {
  waiting_job: 'groupIndustry.planning.wait.waitingJob',
  waiting_materials: 'groupIndustry.planning.wait.waitingMaterials',
  unknown: 'groupIndustry.planning.wait.unknown',
} as const

const label = computed(() => t(WAIT_MESSAGE_KEYS[props.wait.kind], { names: props.wait.names.join(', ') }))
const lateness = computed(() => t('groupIndustry.planning.wait.late', { duration: formatDuration(props.wait.lateSeconds) }))
</script>

<template>
  <span :data-kind="wait.kind">{{ label }} · {{ lateness }}</span>
</template>
