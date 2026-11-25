<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  used: number
  max: number | null
}>()

const { t } = useI18n()

const isOverMaximum = computed(() => props.max !== null && props.used > props.max)

const tooltip = computed(() => {
  if (props.max === null) return t('pi.slots.unknownTooltip')
  if (isOverMaximum.value) return t('pi.slots.overTooltip')
  return undefined
})
</script>

<template>
  <span
    data-testid="slots-badge"
    :title="tooltip"
    :class="[
      'inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full border font-mono-tech whitespace-nowrap',
      isOverMaximum
        ? 'text-red-400 bg-red-500/10 border-red-500/40'
        : 'text-cyan-400 bg-cyan-500/10 border-cyan-500/30',
    ]"
  >
    {{ t('pi.slots.badge', { used, max: max === null ? t('pi.slots.unknownMax') : max }) }}
  </span>
</template>
