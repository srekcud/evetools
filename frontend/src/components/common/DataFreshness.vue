<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import {
  dataFreshnessLevel,
  readableLastSuccessAt,
  type DataFreshnessLevel,
  type DataFreshnessSource,
  type DataFreshnessState,
} from '@/utils/dataFreshness'

const REFRESH_INTERVAL_MS = 60_000
const LABEL_SEPARATOR = ' · '

const LEVEL_CLASSES: Record<DataFreshnessLevel, string> = {
  fresh: 'text-emerald-400',
  aging: 'text-amber-400',
  stale: 'text-red-400',
  unknown: 'text-slate-400',
}

const props = defineProps<{
  source: DataFreshnessSource
  freshness: DataFreshnessState | null
}>()

const { t } = useI18n()
const { formatTimeSince, formatDateTime } = useFormatters()

const now = ref(new Date())
let refreshTimer: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  refreshTimer = setInterval(() => {
    now.value = new Date()
  }, REFRESH_INTERVAL_MS)
})

onBeforeUnmount(() => {
  clearInterval(refreshTimer)
})

const level = computed(() => dataFreshnessLevel(props.freshness, now.value))

const lastSuccessAt = computed(() => readableLastSuccessAt(props.freshness))

const label = computed(() => {
  const parts = [t(`common.dataFreshness.sources.${props.source}`)]
  parts.push(lastSuccessAt.value === null
    ? t('common.dataFreshness.unknownDate')
    : formatTimeSince(lastSuccessAt.value, now.value))
  if (props.freshness?.lastRunFailed) parts.push(t('common.dataFreshness.lastSyncFailed'))
  return parts.join(LABEL_SEPARATOR)
})

const tooltip = computed(() => lastSuccessAt.value === null ? undefined : formatDateTime(lastSuccessAt.value))
</script>

<template>
  <span
    data-testid="data-freshness"
    :data-level="level"
    :title="tooltip"
    class="inline-flex items-center gap-1.5 text-xs"
    :class="LEVEL_CLASSES[level]"
  >
    <span class="w-1.5 h-1.5 rounded-full bg-current" aria-hidden="true" />
    {{ label }}
  </span>
</template>
