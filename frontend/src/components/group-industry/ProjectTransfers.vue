<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { usePilotName } from '@/composables/usePilotName'
import { useGroupSettlementStore } from '@/stores/group-industry/settlement'
import type { ProjectTransfer } from '@/stores/group-industry/types'

const COPIED_FEEDBACK_MILLISECONDS = 2000

const props = defineProps<{ projectId: string }>()

const { t } = useI18n()
const { formatIskExact } = useFormatters()
const { pilotName } = usePilotName()
const settlementStore = useGroupSettlementStore()
const settlement = computed(() => settlementStore.settlement)

const copied = ref(false)
const copyFailed = ref(false)
let copiedTimer: ReturnType<typeof setTimeout> | undefined

async function copyReference(): Promise<void> {
  if (settlement.value === null) return
  copyFailed.value = false
  try {
    await navigator.clipboard.writeText(settlement.value.reference)
    copied.value = true
    clearTimeout(copiedTimer)
    copiedTimer = setTimeout(() => { copied.value = false }, COPIED_FEEDBACK_MILLISECONDS)
  } catch {
    copied.value = false
    copyFailed.value = true
  }
}

function tick(transfer: ProjectTransfer, event: Event): void {
  settlementStore.setReceived(props.projectId, transfer, (event.target as HTMLInputElement).checked)
}

onBeforeUnmount(() => clearTimeout(copiedTimer))
</script>

<template>
  <section v-if="settlement !== null" data-testid="project-transfers" class="mt-8 bg-slate-900/80 rounded-xl border border-slate-800 p-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
      <h2 class="text-lg font-semibold text-slate-100">{{ t('groupIndustry.transfers.title') }}</h2>
      <div class="flex items-center gap-2">
        <span class="text-xs text-slate-400">{{ t('groupIndustry.transfers.reference') }}</span>
        <code data-testid="transfer-reference" class="rounded-sm border border-cyan-500/30 bg-slate-950/60 px-2 py-1 font-mono text-sm text-cyan-200">{{ settlement.reference }}</code>
        <button
          type="button"
          data-testid="copy-transfer-reference"
          class="rounded-md border border-cyan-500/40 px-3 py-1 text-xs text-cyan-200 hover:bg-cyan-500/10"
          @click="copyReference"
        >{{ copied ? t('groupIndustry.transfers.copied') : t('groupIndustry.transfers.copy') }}</button>
      </div>
    </div>
    <p class="mb-3 text-xs text-slate-400">{{ t('groupIndustry.transfers.hint') }}</p>
    <p v-if="copyFailed" class="mb-3 rounded-sm border border-amber-500/30 bg-amber-500/5 px-3 py-1.5 text-xs text-amber-200" role="alert">{{ t('groupIndustry.transfers.copyFailed') }}</p>

    <p
      v-if="settlement.transfers === null"
      data-testid="transfers-unavailable"
      :data-reason="settlement.transfersUnavailableReason"
      class="rounded-sm border border-slate-700 bg-slate-950/60 px-3 py-2 text-sm text-slate-300"
    >{{ t(`groupIndustry.transfers.unavailable.${settlement.transfersUnavailableReason}`) }}</p>
    <p v-else-if="settlement.transfers.length === 0" data-testid="transfers-none" class="text-sm text-slate-300">{{ t('groupIndustry.transfers.none') }}</p>
    <ul v-else class="divide-y divide-slate-800">
      <li
        v-for="transfer in settlement.transfers"
        :key="`${transfer.fromParticipantId}-${transfer.toParticipantId}`"
        :data-testid="`transfer-row-${transfer.fromParticipantId}-${transfer.toParticipantId}`"
        class="flex flex-wrap items-center gap-3 py-2 text-sm"
      >
        <span class="min-w-0 flex-1 text-slate-200">
          <span class="font-medium">{{ pilotName(transfer.fromName) }}</span>
          <span class="mx-2 text-slate-500" aria-hidden="true">→</span>
          <span class="font-medium">{{ pilotName(transfer.toName) }}</span>
        </span>
        <span class="font-mono text-slate-100">{{ formatIskExact(transfer.amount) }}</span>
        <label class="flex items-center gap-2 text-xs text-slate-300" :title="transfer.canConfirm ? undefined : t('groupIndustry.transfers.onlyRecipient')">
          <input
            type="checkbox"
            :data-testid="`transfer-received-${transfer.fromParticipantId}-${transfer.toParticipantId}`"
            :checked="transfer.received"
            :disabled="!transfer.canConfirm"
            @change="tick(transfer, $event)"
          >
          {{ t('groupIndustry.transfers.received') }}
        </label>
      </li>
    </ul>

    <p v-if="settlement.roundingGap !== 0" data-testid="transfer-rounding-gap" class="mt-3 text-xs text-slate-400">
      {{ t('groupIndustry.transfers.roundingGap', { amount: formatIskExact(settlement.roundingGap) }) }}
    </p>
    <p v-if="settlementStore.error" class="mt-3 text-xs text-red-400" role="alert">{{ settlementStore.error }}</p>
  </section>
</template>
