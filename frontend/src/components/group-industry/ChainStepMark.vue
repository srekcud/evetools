<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupChainStore } from '@/stores/group-industry/chain'
import type { ChainNode, ChainStepMarkState } from '@/stores/group-industry/types'

const STATES: readonly ChainStepMarkState[] = ['launched', 'done']
const MINIMUM_RUNS = 1

const props = defineProps<{
  projectId: string
  node: ChainNode
  canMark: boolean
}>()

const emit = defineEmits<{
  marked: []
}>()

const { t } = useI18n()
const chainStore = useGroupChainStore()

const state = ref<ChainStepMarkState>('launched')
const runs = ref<number | ''>(props.node.runs ?? '')
const errorMessage = ref<string | null>(null)
const pending = ref(false)

function settle(succeeded: boolean): void {
  errorMessage.value = succeeded ? null : chainStore.markError
  if (succeeded) emit('marked')
}

async function submit(): Promise<void> {
  if (runs.value === '' || pending.value) return
  pending.value = true
  try {
    settle(await chainStore.markStep(props.projectId, props.node.id, state.value, runs.value))
  } finally {
    pending.value = false
  }
}

async function remove(): Promise<void> {
  if (pending.value) return
  pending.value = true
  try {
    settle(await chainStore.unmarkStep(props.projectId, props.node.id))
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <div v-if="node.mark !== null || canMark" class="mt-1 space-y-1">
    <span
      v-if="node.mark !== null"
      data-testid="chain-step-mark-badge"
      class="inline-flex items-center px-1.5 py-0.5 rounded-sm border border-cyan-500/30 bg-cyan-500/10 text-xs text-cyan-300 whitespace-nowrap"
      :title="t('groupIndustry.chain.mark.badge')"
    >{{ t(node.mark.state === 'done' ? 'groupIndustry.chain.mark.markedDone' : 'groupIndustry.chain.mark.markedLaunched', { runs: node.mark.runs }) }}</span>

    <form
      v-if="canMark && node.runs !== null"
      data-testid="chain-step-mark-form"
      class="flex items-center gap-1"
      @submit.prevent="submit"
    >
      <select
        v-model="state"
        data-testid="chain-step-mark-state"
        :aria-label="t('groupIndustry.chain.mark.title')"
        class="bg-slate-900 border border-slate-700 rounded-sm px-1 py-0.5 text-xs text-slate-300 focus:border-cyan-500 focus:outline-none"
      >
        <option v-for="option in STATES" :key="option" :value="option">{{ t(`groupIndustry.chain.mark.${option}`) }}</option>
      </select>
      <input
        v-model.number="runs"
        data-testid="chain-step-mark-runs"
        type="number"
        :min="MINIMUM_RUNS"
        :max="node.runs"
        step="1"
        required
        :aria-label="t('groupIndustry.chain.mark.runs')"
        class="w-14 bg-slate-900 border border-slate-700 rounded-sm px-1 py-0.5 text-xs font-mono text-slate-300 focus:border-cyan-500 focus:outline-none"
      />
      <button
        type="submit"
        data-testid="chain-step-mark-submit"
        :disabled="pending"
        class="px-1.5 py-0.5 rounded-sm border border-cyan-500/40 bg-cyan-500/10 text-xs text-cyan-300 hover:bg-cyan-500/20 disabled:opacity-50"
      >{{ t('groupIndustry.chain.mark.submit') }}</button>
    </form>

    <button
      v-if="canMark && node.mark !== null"
      type="button"
      data-testid="chain-step-mark-remove"
      :disabled="pending"
      class="block text-xs text-slate-500 hover:text-red-300 disabled:opacity-50"
      @click="remove"
    >{{ t('groupIndustry.chain.mark.remove') }}</button>

    <p v-if="errorMessage !== null" data-testid="chain-step-mark-error" class="text-xs text-red-300">{{ errorMessage }}</p>
  </div>
</template>
