<script setup lang="ts">
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupReactionStepsStore } from '@/stores/group-industry/reactionSteps'
import { useSyncStore } from '@/stores/sync'
import type { ReactionStep } from '@/stores/group-industry/types'

const SECONDS_PER_HOUR = 3_600
const PERCENT = 100
const SHARE_DECIMALS = 1
const HOUR_DECIMALS = 1
const NO_VALUE = '—'

const props = defineProps<{
  projectId: string
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()
const syncStore = useSyncStore()
const reactionStepsStore = useGroupReactionStepsStore()

const hasSurplus = computed(() => reactionStepsStore.steps.some(step => (step.surplusQuantity ?? 0) > 0))
const isLoadingFirstRead = computed(() => reactionStepsStore.loading && reactionStepsStore.steps.length === 0)
const isVisible = computed(() => isLoadingFirstRead.value || reactionStepsStore.error !== null || reactionStepsStore.steps.length > 0)

function formatHours(seconds: number | null): string {
  if (seconds === null) return NO_VALUE
  return t('groupIndustry.reactionSteps.hours', { hours: formatNumber(seconds / SECONDS_PER_HOUR, HOUR_DECIMALS) })
}

function surplusShare(step: ReactionStep): string {
  if (step.surplusQuantity === null) return NO_VALUE
  return `${formatNumber((step.surplusQuantity / step.producedQuantity) * PERCENT, SHARE_DECIMALS)} %`
}

function quantityOrDash(quantity: number | null): string {
  return quantity === null ? NO_VALUE : formatNumber(quantity, 0)
}

watch(() => props.projectId, projectId => reactionStepsStore.fetchReactionSteps(projectId), { immediate: true })

watch(
  () => syncStore.getGroupProjectEvent(props.projectId),
  (event) => {
    if (!event) return
    reactionStepsStore.fetchReactionSteps(props.projectId)
  },
)

watch(
  () => syncStore.getSyncProgress('industry-project'),
  (progress) => {
    if (progress?.data?.projectId !== props.projectId) return
    reactionStepsStore.fetchReactionSteps(props.projectId)
  },
)
</script>

<template>
  <section
    v-if="isVisible"
    data-testid="reaction-steps"
    class="border-t border-slate-800"
  >
    <header class="px-4 py-3 border-b border-slate-800">
      <h3 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
        {{ t('groupIndustry.reactionSteps.title') }}
      </h3>
    </header>

    <p
      v-if="isLoadingFirstRead"
      data-testid="reaction-steps-loading"
      class="p-6 text-center text-slate-500"
    >{{ t('groupIndustry.reactionSteps.loading') }}</p>

    <div
      v-else-if="reactionStepsStore.error !== null"
      data-testid="reaction-steps-error"
      role="alert"
      class="m-4 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
    >
      <p class="font-semibold">{{ t('groupIndustry.reactionSteps.loadError') }}</p>
      <p class="mt-1 break-words">{{ reactionStepsStore.error }}</p>
    </div>

    <template v-else>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-800/50">
              <th class="text-left py-2.5 px-4">{{ t('groupIndustry.reactionSteps.columns.reaction') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('groupIndustry.reactionSteps.columns.needed') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('groupIndustry.reactionSteps.columns.runsDuration') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('groupIndustry.reactionSteps.columns.produced') }}</th>
              <th class="text-right py-2.5 px-4">{{ t('groupIndustry.reactionSteps.columns.surplus') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="step in reactionStepsStore.steps"
              :key="step.typeId"
              data-testid="reaction-step-row"
              :data-type-id="step.typeId"
              class="border-b border-slate-800/50 align-top"
            >
              <td class="py-2.5 px-4">
                <span class="block truncate min-w-0 max-w-64 font-medium text-slate-200" :title="step.typeName">{{ step.typeName }}</span>
                <p
                  v-if="step.inputs === null"
                  data-testid="reaction-step-formula-unknown"
                  class="mt-1 text-xs text-violet-300"
                >{{ t('groupIndustry.reactionSteps.formulaUnknown') }}</p>
                <p v-else data-testid="reaction-step-formula" class="mt-1 text-xs text-slate-400">
                  <template v-for="(input, index) in step.inputs" :key="input.typeId">
                    <span v-if="index > 0"> · </span>
                    <span
                      data-testid="reaction-step-input"
                      :data-type-id="input.typeId"
                      :data-fuel-block="input.isFuelBlock ? 'true' : 'false'"
                      :class="input.isFuelBlock ? 'text-amber-300' : ''"
                    >{{ input.typeName ?? input.typeId }} ×{{ formatNumber(input.quantityPerRun, 0) }}</span>
                  </template>
                </p>
              </td>
              <td data-testid="reaction-step-needed" class="py-2.5 px-3 text-right font-mono text-slate-300">{{ quantityOrDash(step.neededQuantity) }}</td>
              <td data-testid="reaction-step-runs-duration" class="py-2.5 px-3 text-right font-mono text-slate-300">{{ formatNumber(step.runs, 0) }} · {{ formatHours(step.baseTotalSeconds) }}</td>
              <td data-testid="reaction-step-produced" class="py-2.5 px-3 text-right font-mono text-slate-300">{{ formatNumber(step.producedQuantity, 0) }}</td>
              <td data-testid="reaction-step-surplus" class="py-2.5 px-4 text-right font-mono" :class="(step.surplusQuantity ?? 0) > 0 ? 'text-amber-300' : 'text-slate-300'">
                <template v-if="step.surplusQuantity === null">
                  <span>{{ NO_VALUE }}</span>
                  <span data-testid="reaction-step-surplus-unknown" class="block text-xs font-sans text-violet-300">{{ t('groupIndustry.reactionSteps.surplusUnknown') }}</span>
                </template>
                <template v-else>{{ formatNumber(step.surplusQuantity, 0) }} ({{ surplusShare(step) }})</template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="hasSurplus"
        data-testid="reaction-steps-warning"
        role="status"
        class="m-4 p-3 rounded border border-amber-500/30 bg-amber-500/10 text-sm text-amber-200"
      >{{ t('groupIndustry.reactionSteps.warning') }}</div>

      <p class="px-4 pb-3 text-xs text-slate-500">
        {{ t('groupIndustry.reactionSteps.baseDurationHint') }} {{ t('groupIndustry.reactionSteps.costsHint') }}
      </p>
    </template>
  </section>
</template>
