<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupT2BlueprintCopyProposalStore } from '@/stores/group-industry/t2BlueprintCopyProposal'
import type { GroupProjectStatus, T2BlueprintCopyProposalLine, T2BlueprintCopySelection } from '@/stores/group-industry/types'

const props = defineProps<{
  projectId: string
  projectStatus: GroupProjectStatus
}>()

const emit = defineEmits<{
  declared: []
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()
const proposalStore = useGroupT2BlueprintCopyProposalStore()

const selected = ref<Record<number, boolean>>({})
const runs = ref<Record<number, number | string>>({})
const folded = ref(true)

const isDraft = computed(() => props.projectStatus === 'draft')
const proposal = computed(() => proposalStore.proposal)
const lines = computed(() => proposal.value?.lines ?? [])
const isReady = computed(() => proposal.value?.state === 'ready')
const isUnknown = computed(() => proposal.value?.state === 'assets_unknown')
const isInventionExcluded = computed(() => proposal.value?.state === 'invention_excluded')
const hasLoadFailed = computed(() => proposal.value === null && proposalStore.error !== null)
const isVisible = computed(() => {
  if (hasLoadFailed.value || isInventionExcluded.value) return true
  if (isDraft.value) return isReady.value || isUnknown.value
  return isReady.value
})
const isOpen = computed(() => isDraft.value || hasLoadFailed.value || !folded.value)

function isValidRuns(line: T2BlueprintCopyProposalLine): boolean {
  const value = runs.value[line.productTypeId]
  return typeof value === 'number' && Number.isInteger(value) && value >= 1 && value <= line.proposedRuns
}

const selectedLines = computed(() => lines.value.filter(line => selected.value[line.productTypeId] === true))
const differsFromDeclared = computed(() =>
  selectedLines.value.length !== lines.value.filter(line => line.declaredRuns > 0).length
  || selectedLines.value.some(line => line.declaredRuns !== runs.value[line.productTypeId]),
)
const canDeclare = computed(() =>
  !proposalStore.declaring && selectedLines.value.every(isValidRuns) && differsFromDeclared.value,
)

watch(lines, current => {
  selected.value = Object.fromEntries(current.map(line => [line.productTypeId, line.declaredRuns > 0]))
  runs.value = Object.fromEntries(current.map(line => [line.productTypeId, line.declaredRuns > 0 ? line.declaredRuns : line.proposedRuns]))
}, { immediate: true })

watch(() => props.projectId, projectId => proposalStore.fetchProposal(projectId), { immediate: true })

async function declare(): Promise<void> {
  const selections: T2BlueprintCopySelection[] = selectedLines.value.map(line => ({
    productTypeId: line.productTypeId,
    runs: Number(runs.value[line.productTypeId]),
  }))
  await proposalStore.declare(props.projectId, selections)
  if (proposalStore.declarationError === null) {
    emit('declared')
  }
}
</script>

<template>
  <section
    v-if="isVisible"
    data-testid="t2-blueprint-copy-proposal"
    class="mb-4 bg-slate-900/80 rounded-xl border border-cyan-500/20 overflow-hidden"
  >
    <header class="px-4 py-3 flex items-center justify-between gap-3" :class="{ 'border-b border-slate-800': isOpen }">
      <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
        {{ t('groupIndustry.t2BlueprintCopyProposal.title') }}
      </h2>
      <button
        v-if="!isDraft && !hasLoadFailed"
        type="button"
        data-testid="t2-blueprint-copy-proposal-toggle"
        :aria-expanded="!folded"
        class="px-3 py-1.5 bg-cyan-600/20 hover:bg-cyan-600/30 rounded-lg text-cyan-400 text-xs font-medium border border-cyan-500/25 transition-colors"
        @click="folded = !folded"
      >{{ t('groupIndustry.t2BlueprintCopyProposal.toggle') }}</button>
    </header>

    <template v-if="isOpen">
      <div
        v-if="proposalStore.declarationError !== null"
        data-testid="t2-blueprint-copy-proposal-error"
        role="alert"
        class="m-4 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
      >
        <p class="font-semibold">{{ t('groupIndustry.t2BlueprintCopyProposal.declarationFailed') }}</p>
        <p class="mt-1 break-words">{{ proposalStore.declarationError }}</p>
      </div>

      <div
        v-if="hasLoadFailed"
        data-testid="t2-blueprint-copy-proposal-load-error"
        role="alert"
        class="m-4 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
      >
        <p class="font-semibold">{{ t('groupIndustry.t2BlueprintCopyProposal.loadError') }}</p>
        <p class="mt-1 break-words">{{ proposalStore.error }}</p>
      </div>

      <p
        v-else-if="isInventionExcluded"
        data-testid="t2-blueprint-copy-proposal-invention-excluded"
        class="m-4 p-3 rounded border border-slate-700 bg-slate-950/60 text-sm text-slate-300"
      >{{ t('groupIndustry.t2BlueprintCopyProposal.inventionExcluded') }}</p>

      <div
        v-else-if="isUnknown && proposal?.unknownReason"
        data-testid="t2-blueprint-copy-proposal-unknown"
        class="m-4 p-3 rounded border border-amber-500/30 bg-amber-500/10 text-sm text-amber-300"
      >
        <p class="font-semibold">{{ t('groupIndustry.t2BlueprintCopyProposal.unknownTitle') }}</p>
        <p class="mt-1">{{ t(`groupIndustry.t2BlueprintCopyProposal.unknown.${proposal.unknownReason}`) }}</p>
      </div>

      <p
        v-else-if="lines.length === 0"
        data-testid="t2-blueprint-copy-proposal-empty"
        class="p-6 text-center text-slate-500"
      >{{ t('groupIndustry.t2BlueprintCopyProposal.empty') }}</p>

      <template v-else>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
                <th class="px-4 py-2 w-8"></th>
                <th class="px-4 py-2 font-medium">{{ t('groupIndustry.t2BlueprintCopyProposal.columns.type') }}</th>
                <th class="px-4 py-2 font-medium text-right">{{ t('groupIndustry.t2BlueprintCopyProposal.columns.manufacturing') }}</th>
                <th class="px-4 py-2 font-medium text-right">{{ t('groupIndustry.t2BlueprintCopyProposal.columns.personal') }}</th>
                <th class="px-4 py-2 font-medium text-right">{{ t('groupIndustry.t2BlueprintCopyProposal.columns.container') }}</th>
                <th class="px-4 py-2 font-medium text-right">{{ t('groupIndustry.t2BlueprintCopyProposal.columns.available') }}</th>
                <th class="px-4 py-2 font-medium text-right">{{ t('groupIndustry.t2BlueprintCopyProposal.columns.runs') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/60">
              <tr
                v-for="line in lines"
                :key="line.productTypeId"
                :data-testid="`t2-blueprint-copy-proposal-row-${line.productTypeId}`"
              >
                <td class="px-4 py-2">
                  <input
                    v-model="selected[line.productTypeId]"
                    type="checkbox"
                    :aria-label="line.typeName"
                    :data-testid="`t2-blueprint-copy-proposal-select-${line.productTypeId}`"
                    class="accent-cyan-500"
                  />
                </td>
                <td class="px-4 py-2 font-medium text-slate-100 max-w-[18rem] truncate" :title="line.typeName">{{ line.typeName }}</td>
                <td
                  :data-testid="`t2-blueprint-copy-proposal-cell-${line.productTypeId}-manufacturing`"
                  class="px-4 py-2 text-right tabular-nums text-slate-400"
                >{{ formatNumber(line.manufacturingRuns, 0) }}</td>
                <td
                  :data-testid="`t2-blueprint-copy-proposal-cell-${line.productTypeId}-personal`"
                  class="px-4 py-2 text-right tabular-nums text-slate-400"
                >{{ formatNumber(line.personalRuns, 0) }}</td>
                <td
                  :data-testid="`t2-blueprint-copy-proposal-cell-${line.productTypeId}-container`"
                  class="px-4 py-2 text-right tabular-nums text-slate-400"
                >{{ line.containerRuns === null ? '—' : formatNumber(line.containerRuns, 0) }}</td>
                <td
                  :data-testid="`t2-blueprint-copy-proposal-cell-${line.productTypeId}-available`"
                  class="px-4 py-2 text-right tabular-nums text-cyan-300"
                >{{ formatNumber(line.availableRuns, 0) }}</td>
                <td class="px-4 py-2 text-right">
                  <input
                    v-model.number="runs[line.productTypeId]"
                    type="number"
                    :aria-label="`${t('groupIndustry.t2BlueprintCopyProposal.columns.runs')} ${line.typeName}`"
                    min="1"
                    :max="line.proposedRuns"
                    step="1"
                    :data-testid="`t2-blueprint-copy-proposal-runs-${line.productTypeId}`"
                    class="w-24 px-2 py-1 bg-slate-950 border rounded text-right tabular-nums text-slate-100 focus:outline-none focus:border-cyan-500/60"
                    :class="selected[line.productTypeId] && !isValidRuns(line) ? 'border-red-500/60' : 'border-slate-700'"
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <footer class="px-4 py-3 border-t border-slate-800 flex justify-end">
          <button
            type="button"
            data-testid="t2-blueprint-copy-proposal-declare"
            :disabled="!canDeclare"
            class="px-3 py-1.5 bg-cyan-600/20 hover:bg-cyan-600/30 rounded-lg text-cyan-400 text-xs font-medium border border-cyan-500/25 transition-colors disabled:opacity-50"
            @click="declare"
          >{{ proposalStore.declaring ? t('groupIndustry.t2BlueprintCopyProposal.declaring') : t('groupIndustry.t2BlueprintCopyProposal.declare') }}</button>
        </footer>
      </template>
    </template>
  </section>
</template>
