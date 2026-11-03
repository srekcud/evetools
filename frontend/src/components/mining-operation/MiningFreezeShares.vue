<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MiningOperationValuation, MiningShareBlocker } from '@/stores/miningOperation'
import { missingTypeNames, unknownParticipantNames } from '@/views/MiningOperation/miningOperationValuation'

const props = defineProps<{
  valuation: MiningOperationValuation
}>()

const emit = defineEmits<{
  freeze: []
}>()

const { t } = useI18n()

const unknownNames = computed(() => unknownParticipantNames(props.valuation, t('miningOperation.unknownPilot')))
const missingTypes = computed(() => missingTypeNames(props.valuation))

function blockerText(blocker: MiningShareBlocker): string {
  return t(`miningOperation.freeze.blockers.${blocker}`, { names: unknownNames.value, types: missingTypes.value })
}
</script>

<template>
  <section class="eve-card p-5">
    <h2 class="mb-2 font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.freeze.title') }}</h2>
    <p class="mb-3 text-sm text-slate-300">{{ t('miningOperation.freeze.hint') }}</p>
    <ul v-if="valuation.freezeBlockers.length > 0" class="mb-3 space-y-1">
      <li v-for="blocker in valuation.freezeBlockers" :key="blocker" class="rounded-sm border border-amber-500/30 bg-amber-500/5 px-3 py-1.5 text-xs text-amber-200">{{ blockerText(blocker) }}</li>
    </ul>
    <button
      type="button"
      class="rounded-md bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-50"
      :disabled="!valuation.canFreeze"
      @click="emit('freeze')"
    >{{ t('miningOperation.freeze.action') }}</button>
  </section>
</template>
