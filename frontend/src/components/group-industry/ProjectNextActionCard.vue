<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ProjectNextAction } from '@/stores/group-industry/types'

const props = defineProps<{
  nextAction: ProjectNextAction
  disabled: boolean
}>()

defineEmits<{
  act: []
}>()

const { t } = useI18n()

const messageKey = computed(() => `groupIndustry.cockpit.nextAction.${props.nextAction.code}`)
const descriptionKey = computed(() =>
  props.nextAction.code === 'launch_job' && props.nextAction.quantity === null
    ? `${messageKey.value}.descriptionUnknownRuns`
    : `${messageKey.value}.description`
)
const hasButton = computed(() => props.nextAction.targetStatus !== null || props.nextAction.tab !== null)
</script>

<template>
  <section
    data-testid="next-action-card"
    :data-blocked="nextAction.blocked ? 'true' : 'false'"
    class="mb-6 flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
    :class="nextAction.blocked ? 'border-amber-500/40 bg-amber-500/10' : 'border-cyan-500/30 bg-slate-900/80'"
  >
    <div class="min-w-0">
      <p class="text-xs uppercase tracking-wider" :class="nextAction.blocked ? 'text-amber-400' : 'text-cyan-400'">
        {{ t('groupIndustry.cockpit.nextAction.label') }}
      </p>
      <h2 data-testid="next-action-title" class="text-base font-bold text-slate-100">
        {{ t(`${messageKey}.title`) }}{{ ' ' }}
      </h2>
      <p class="break-words text-sm text-slate-400">
        {{ t(descriptionKey, { typeName: nextAction.typeName, quantity: nextAction.quantity }) }}
      </p>
    </div>
    <button
      v-if="hasButton"
      data-testid="next-action-button"
      type="button"
      class="flex-shrink-0 rounded-lg px-4 py-2.5 text-sm font-medium text-white transition-all hover:-translate-y-px disabled:opacity-50"
      :class="nextAction.blocked ? 'bg-amber-600 hover:bg-amber-500' : 'bg-cyan-600 hover:bg-cyan-500'"
      :disabled="disabled"
      @click="$emit('act')"
    >
      {{ t(`${messageKey}.button`) }}
    </button>
  </section>
</template>
