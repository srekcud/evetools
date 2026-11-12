<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupIntermediateStockProposalStore } from '@/stores/group-industry/intermediateStockProposal'
import type { GroupProjectStatus, IntermediateStockProposalLine, IntermediateStockSelection } from '@/stores/group-industry/types'

const props = defineProps<{
  projectId: string
  projectStatus: GroupProjectStatus
}>()

const emit = defineEmits<{
  validated: []
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()
const proposalStore = useGroupIntermediateStockProposalStore()

const selected = ref<Record<string, boolean>>({})
const quantities = ref<Record<string, number | string>>({})
const folded = ref(true)

const isDraft = computed(() => props.projectStatus === 'draft')
const proposal = computed(() => proposalStore.proposal)
const lines = computed(() => proposal.value?.lines ?? [])
const isReady = computed(() => proposal.value?.state === 'ready')
const isUnknown = computed(() => proposal.value?.state === 'assets_unknown')
const hasLoadFailed = computed(() => proposal.value === null && proposalStore.error !== null)
const isVisible = computed(() => {
  if (hasLoadFailed.value) return true
  if (isDraft.value) return isReady.value || isUnknown.value
  return isReady.value
})
const isOpen = computed(() => isDraft.value || hasLoadFailed.value || !folded.value)

function isValidQuantity(line: IntermediateStockProposalLine): boolean {
  const quantity = quantities.value[line.bomItemId]
  return typeof quantity === 'number' && Number.isInteger(quantity) && quantity >= 1 && quantity <= line.proposedQuantity
}

const selectedLines = computed(() => lines.value.filter(line => selected.value[line.bomItemId] === true))
const canValidate = computed(() =>
  !proposalStore.validating && selectedLines.value.length > 0 && selectedLines.value.every(isValidQuantity),
)

watch(lines, current => {
  selected.value = Object.fromEntries(current.map(line => [line.bomItemId, true]))
  quantities.value = Object.fromEntries(current.map(line => [line.bomItemId, line.proposedQuantity]))
}, { immediate: true })

watch(() => props.projectId, projectId => proposalStore.fetchProposal(projectId), { immediate: true })

async function validate(): Promise<void> {
  const selections: IntermediateStockSelection[] = selectedLines.value.map(line => ({
    bomItemId: line.bomItemId,
    quantity: Number(quantities.value[line.bomItemId]),
  }))
  await proposalStore.validate(props.projectId, selections)
  if (proposalStore.validationError === null) {
    emit('validated')
  }
}
</script>

<template>
  <section
    v-if="isVisible"
    data-testid="intermediate-stock-proposal"
    class="mb-4 bg-slate-900/80 rounded-xl border border-cyan-500/20 overflow-hidden"
  >
    <header class="px-4 py-3 flex items-center justify-between gap-3" :class="{ 'border-b border-slate-800': isOpen }">
      <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
        {{ t('groupIndustry.intermediateStockProposal.title') }}
      </h2>
      <button
        v-if="!isDraft && !hasLoadFailed"
        type="button"
        data-testid="intermediate-stock-proposal-toggle"
        :aria-expanded="!folded"
        class="px-3 py-1.5 bg-cyan-600/20 hover:bg-cyan-600/30 rounded-lg text-cyan-400 text-xs font-medium border border-cyan-500/25 transition-colors"
        @click="folded = !folded"
      >{{ t('groupIndustry.intermediateStockProposal.toggle') }}</button>
    </header>

    <template v-if="isOpen">
      <div
        v-if="proposalStore.validationError !== null"
        data-testid="intermediate-stock-proposal-error"
        role="alert"
        class="m-4 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
      >
        <p class="font-semibold">{{ t('groupIndustry.intermediateStockProposal.validationFailed') }}</p>
        <p class="mt-1 break-words">{{ proposalStore.validationError }}</p>
      </div>

      <div
        v-if="hasLoadFailed"
        data-testid="intermediate-stock-proposal-load-error"
        role="alert"
        class="m-4 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
      >
        <p class="font-semibold">{{ t('groupIndustry.intermediateStockProposal.loadError') }}</p>
        <p class="mt-1 break-words">{{ proposalStore.error }}</p>
      </div>

      <div
        v-else-if="isUnknown && proposal?.unknownReason"
        data-testid="intermediate-stock-proposal-unknown"
        class="m-4 p-3 rounded border border-amber-500/30 bg-amber-500/10 text-sm text-amber-300"
      >
        <p class="font-semibold">{{ t('groupIndustry.intermediateStockProposal.unknownTitle') }}</p>
        <p class="mt-1">{{ t(`groupIndustry.intermediateStockProposal.unknown.${proposal.unknownReason}`) }}</p>
      </div>

      <p
        v-else-if="lines.length === 0"
        data-testid="intermediate-stock-proposal-empty"
        class="p-6 text-center text-slate-500"
      >{{ t('groupIndustry.intermediateStockProposal.empty') }}</p>

      <template v-else>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
                <th class="px-4 py-2 w-8"></th>
                <th class="px-4 py-2 font-medium">{{ t('groupIndustry.intermediateStockProposal.columns.type') }}</th>
                <th class="px-4 py-2 font-medium text-right">{{ t('groupIndustry.intermediateStockProposal.columns.remaining') }}</th>
                <th class="px-4 py-2 font-medium text-right">{{ t('groupIndustry.intermediateStockProposal.columns.personal') }}</th>
                <th class="px-4 py-2 font-medium text-right">{{ t('groupIndustry.intermediateStockProposal.columns.container') }}</th>
                <th class="px-4 py-2 font-medium text-right">{{ t('groupIndustry.intermediateStockProposal.columns.stock') }}</th>
                <th class="px-4 py-2 font-medium text-right">{{ t('groupIndustry.intermediateStockProposal.columns.quantity') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/60">
              <tr
                v-for="line in lines"
                :key="line.bomItemId"
                :data-testid="`intermediate-stock-proposal-row-${line.bomItemId}`"
              >
                <td class="px-4 py-2">
                  <input
                    v-model="selected[line.bomItemId]"
                    type="checkbox"
                    :aria-label="line.typeName"
                    :data-testid="`intermediate-stock-proposal-select-${line.bomItemId}`"
                    class="accent-cyan-500"
                  />
                </td>
                <td class="px-4 py-2 font-medium text-slate-100 max-w-[18rem] truncate" :title="line.typeName">{{ line.typeName }}</td>
                <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ formatNumber(line.remainingQuantity, 0) }}</td>
                <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ formatNumber(line.personalQuantity, 0) }}</td>
                <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ line.containerQuantity === null ? '—' : formatNumber(line.containerQuantity, 0) }}</td>
                <td class="px-4 py-2 text-right tabular-nums text-cyan-300">{{ formatNumber(line.stockQuantity, 0) }}</td>
                <td class="px-4 py-2 text-right">
                  <input
                    v-model.number="quantities[line.bomItemId]"
                    type="number"
                    :aria-label="`${t('groupIndustry.intermediateStockProposal.columns.quantity')} ${line.typeName}`"
                    min="1"
                    :max="line.proposedQuantity"
                    step="1"
                    :data-testid="`intermediate-stock-proposal-quantity-${line.bomItemId}`"
                    class="w-24 px-2 py-1 bg-slate-950 border rounded text-right tabular-nums text-slate-100 focus:outline-none focus:border-cyan-500/60"
                    :class="selected[line.bomItemId] && !isValidQuantity(line) ? 'border-red-500/60' : 'border-slate-700'"
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <footer class="px-4 py-3 border-t border-slate-800 flex justify-end">
          <button
            type="button"
            data-testid="intermediate-stock-proposal-validate"
            :disabled="!canValidate"
            class="px-3 py-1.5 bg-cyan-600/20 hover:bg-cyan-600/30 rounded-lg text-cyan-400 text-xs font-medium border border-cyan-500/25 transition-colors disabled:opacity-50"
            @click="validate"
          >{{ proposalStore.validating ? t('groupIndustry.intermediateStockProposal.validating') : t('groupIndustry.intermediateStockProposal.validate') }}</button>
        </footer>
      </template>
    </template>
  </section>
</template>
