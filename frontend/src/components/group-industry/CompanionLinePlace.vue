<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { CompanionLinePlace } from './companionTasks'

const props = defineProps<{ place: CompanionLinePlace }>()

const { t } = useI18n()

const text = computed(() => {
  const parts = [props.place.stationName, props.place.systemName].filter(part => part !== null)
  return parts.length === 0 ? null : parts.join(' · ')
})
</script>

<template>
  <p data-testid="companion-line-place" class="truncate text-xs text-slate-400" :title="text ?? undefined">
    <template v-if="text !== null">{{ text }}</template>
    <span v-else data-unknown="true" class="italic text-slate-500">{{ t('common.unknownValue') }}</span>
  </p>
</template>
