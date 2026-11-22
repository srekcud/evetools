<script setup lang="ts">
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import UnknownValue from '@/components/common/UnknownValue.vue'
import { buildProjectDiscordRecap } from '@/components/group-industry/projectDiscordRecap'
import { participantRowsOf, settlementCascadeOf, type SettlementCascadeKey } from '@/components/group-industry/projectSettlementBlock'
import { useCopyFeedback } from '@/composables/useCopyFeedback'
import { useFormatters } from '@/composables/useFormatters'
import { usePilotName } from '@/composables/usePilotName'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useGroupSettlementStore } from '@/stores/group-industry/settlement'
import { useGroupSurplusStore } from '@/stores/group-industry/surplus'
import type { ProjectSurplusLine, ProjectTransfer } from '@/stores/group-industry/types'

const props = withDefaults(defineProps<{
  projectId: string
  canManage: boolean
  canClose: boolean
  showTransfers?: boolean
}>(), { showTransfers: true })

defineEmits<{ close: [] }>()

const { t } = useI18n()
const { formatIsk, formatIskExact, formatNumber, formatDate } = useFormatters()
const { pilotName } = usePilotName()
const projectStore = useGroupProjectStore()
const distributionStore = useGroupDistributionStore()
const settlementStore = useGroupSettlementStore()
const surplusStore = useGroupSurplusStore()
const referenceCopy = useCopyFeedback()
const recapCopy = useCopyFeedback()

const project = computed(() => projectStore.currentProject)
const distribution = computed(() => {
  const read = distributionStore.distribution
  return read !== null && Array.isArray(read.members) ? read : null
})
const settlement = computed(() => settlementStore.settlement)
const isCompleted = computed(() => project.value?.status === 'completed')
const cascade = computed(() => (distribution.value === null ? [] : settlementCascadeOf(distribution.value)))
const participantRows = computed(() => (distribution.value === null ? [] : participantRowsOf(distribution.value, settlement.value)))
const surplusCountedByDistribution = computed(() => distribution.value?.surplusStored === true)
const countedSurplusValue = computed((): number | null => (
  surplusCountedByDistribution.value ? distribution.value?.storedSurplusValue ?? null : surplusStore.totalValue
))
const copyFailed = computed(() => referenceCopy.failed.value || recapCopy.failed.value)
const transfersKnown = computed(() => settlement.value !== null && settlement.value.transfers !== null)

const recap = computed((): string | null => {
  if (project.value === null || distribution.value === null || !transfersKnown.value) return null
  return buildProjectDiscordRecap({
    project: project.value,
    distribution: distribution.value,
    settlement: settlement.value,
    t,
    formatIsk: value => formatIsk(value),
    formatExactIsk: formatIskExact,
    pilotName,
  })
})

function cascadeLabel(key: SettlementCascadeKey): string {
  return t(`groupIndustry.settlementBlock.cascade.${key}`)
}

function isSurplusNotStored(key: SettlementCascadeKey): boolean {
  return key === 'surplus' && !surplusCountedByDistribution.value
}

function cascadeSign(key: SettlementCascadeKey): string {
  if (key === 'materials' || key === 'installations') return '− '
  return key === 'surplus' ? '+ ' : ''
}

function lineValueKnown(line: ProjectSurplusLine): boolean {
  return line.source === 'contribution' && line.surplusValue !== null
}

function tick(transfer: ProjectTransfer, event: Event): void {
  settlementStore.setReceived(props.projectId, transfer, (event.target as HTMLInputElement).checked)
}

function copyRecap(): void {
  if (recap.value !== null) recapCopy.copy(recap.value)
}

async function storeSurplus(): Promise<void> {
  await surplusStore.store(props.projectId)
  if (surplusStore.error === null) await distributionStore.fetchDistribution(props.projectId)
}

function loadSettlementData(projectId: string): void {
  void surplusStore.fetchSurplus(projectId)
  if (settlementStore.openedProjectId !== projectId) void settlementStore.fetchForProject(projectId)
}

watch(() => props.projectId, loadSettlementData, { immediate: true })
</script>

<template>
  <section data-testid="settlement-block" class="mt-4 border border-emerald-400/35 bg-emerald-400/[0.04]">
    <header class="flex flex-wrap items-center gap-3 px-3 py-2.5">
      <h2 class="font-display text-sm font-bold uppercase tracking-[0.12em] text-slate-100">{{ t('groupIndustry.settlementBlock.title') }}</h2>
      <span class="text-xs text-slate-400">{{ t('groupIndustry.settlementBlock.subtitle') }}</span>
      <span class="ml-auto">
        <span
          v-if="isCompleted"
          data-testid="settlement-closed-stamp"
          class="border border-emerald-400/50 px-2 py-0.5 font-display text-[11px] font-bold uppercase tracking-[0.14em] text-emerald-300"
        >{{ t('groupIndustry.settlementBlock.closedStamp') }}</span>
        <button
          v-else-if="canClose"
          type="button"
          data-testid="settlement-close"
          class="bg-cyan-600 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-cyan-500"
          @click="$emit('close')"
        >{{ t('groupIndustry.settlementBlock.close') }}</button>
      </span>
    </header>

    <p v-if="distribution === null" data-testid="settlement-distribution-unavailable" class="px-3 pb-3 text-sm text-slate-400" role="status">
      <UnknownValue :loading="distributionStore.loadingDistribution" />
    </p>

    <template v-else>
      <div v-if="showTransfers" class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left font-display text-[11px] font-bold uppercase tracking-[0.12em] text-slate-500">
              <th class="px-3 py-1.5">{{ t('groupIndustry.settlementBlock.columns.participant') }}</th>
              <th class="px-3 py-1.5 text-right">{{ t('groupIndustry.settlementBlock.columns.share') }}</th>
              <th class="px-3 py-1.5 text-right">{{ t('groupIndustry.settlementBlock.columns.receives') }}</th>
              <th class="px-3 py-1.5">{{ t('groupIndustry.settlementBlock.columns.transfer') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in participantRows" :key="row.memberId" class="border-t border-slate-800/60 align-top">
              <td class="max-w-[16rem] truncate px-3 py-1.5 font-medium text-slate-100" :title="pilotName(row.characterName)">{{ pilotName(row.characterName) }}</td>
              <td :data-testid="`settlement-share-${row.memberId}`" class="px-3 py-1.5 text-right tabular-nums text-slate-300">
                <UnknownValue v-if="row.sharePercent === null" />
                <template v-else>{{ formatNumber(row.sharePercent, 0) }} %</template>
              </td>
              <td :data-testid="`settlement-payout-${row.memberId}`" class="px-3 py-1.5 text-right tabular-nums text-slate-100">
                <UnknownValue v-if="row.payoutTotal === null" />
                <template v-else>{{ formatIsk(row.payoutTotal) }}</template>
              </td>
              <td class="px-3 py-1.5">
                <span v-if="row.holdsSales" :data-testid="`settlement-holds-sales-${row.memberId}`" class="text-xs text-slate-400">{{ t('groupIndustry.settlementBlock.holdsSales') }}</span>
                <div
                  v-for="transfer in row.transfers"
                  :key="`${transfer.fromParticipantId}-${transfer.toParticipantId}`"
                  :data-testid="`settlement-transfer-${transfer.fromParticipantId}-${transfer.toParticipantId}`"
                  :data-state="transfer.received ? 'received' : 'pending'"
                  class="flex flex-wrap items-center gap-2 text-xs"
                >
                  <span :class="transfer.received ? 'text-emerald-400' : 'text-amber-400'">
                    {{ transfer.received ? t('groupIndustry.settlementBlock.transferReceived') : t('groupIndustry.settlementBlock.transferAwaiting', { name: pilotName(transfer.fromName) }) }}
                  </span>
                  <label class="flex items-center gap-1.5 text-slate-300" :title="transfer.canConfirm ? undefined : t('groupIndustry.settlementBlock.onlyRecipient')">
                    <input
                      type="checkbox"
                      :data-testid="`settlement-received-${transfer.fromParticipantId}-${transfer.toParticipantId}`"
                      :checked="transfer.received"
                      :disabled="!transfer.canConfirm || transfer.journalVerified"
                      @change="tick(transfer, $event)"
                    >
                    {{ t('groupIndustry.settlementBlock.tickReceived') }}
                  </label>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        <p
          v-if="settlement !== null && settlement.transfers === null"
          data-testid="settlement-transfers-unavailable"
          class="mx-3 mb-2 border border-slate-700 bg-slate-950/60 px-3 py-2 text-sm text-slate-300"
        >{{ t(`groupIndustry.transfers.unavailable.${settlement.transfersUnavailableReason}`) }}</p>
      </div>

      <div class="grid gap-4 border-t border-slate-800/60 p-3 md:grid-cols-2">
        <div>
          <h3 class="mb-1.5 font-display text-[11px] font-bold uppercase tracking-[0.12em] text-slate-400">{{ t('groupIndustry.settlementBlock.cascade.title') }}</h3>
          <dl class="text-sm">
            <div
              v-for="line in cascade"
              :key="line.key"
              :data-testid="`settlement-cascade-${line.key}`"
              class="flex items-baseline justify-between gap-3 border-b border-slate-800/40 py-1"
              :class="line.key === 'profit' ? 'font-bold text-emerald-300' : 'text-slate-300'"
            >
              <dt>{{ cascadeLabel(line.key) }}</dt>
              <dd class="tabular-nums">
                <span v-if="isSurplusNotStored(line.key)" class="text-xs font-normal text-slate-500">{{ t('groupIndustry.settlementBlock.cascade.surplusNotStored') }}</span>
                <UnknownValue v-else-if="line.value === null" />
                <template v-else>{{ cascadeSign(line.key) }}{{ formatIsk(line.value) }}</template>
              </dd>
            </div>
          </dl>
        </div>

        <div v-if="showTransfers">
          <h3 class="mb-1.5 font-display text-[11px] font-bold uppercase tracking-[0.12em] text-slate-400">{{ t('groupIndustry.settlementBlock.recap.title') }}</h3>
          <template v-if="settlement !== null">
            <p class="mb-2 flex flex-wrap items-center gap-2 text-sm text-slate-300">
              <span>{{ t('groupIndustry.settlementBlock.recap.reference') }}</span>
              <code data-testid="settlement-reference" class="border border-cyan-500/30 bg-slate-950/60 px-2 py-0.5 font-mono text-cyan-200">{{ settlement.reference }}</code>
              <button
                type="button"
                data-testid="settlement-copy-reference"
                class="border border-cyan-500/40 px-2.5 py-0.5 text-xs text-cyan-200 hover:bg-cyan-500/10"
                @click="referenceCopy.copy(settlement.reference)"
              >{{ referenceCopy.copied.value ? t('groupIndustry.settlementBlock.recap.copied') : t('groupIndustry.settlementBlock.recap.copy') }}</button>
            </p>
            <p class="mb-2 text-xs text-slate-500">{{ t('groupIndustry.settlementBlock.recap.hint') }}</p>
          </template>
          <p class="flex flex-wrap items-center gap-2 text-sm">
            <button
              type="button"
              data-testid="settlement-copy-discord"
              :disabled="recap === null"
              :title="recap === null ? t('groupIndustry.settlementBlock.recap.discordUnavailable') : undefined"
              class="border border-cyan-500/40 px-2.5 py-0.5 text-xs text-cyan-200 hover:bg-cyan-500/10 disabled:cursor-not-allowed disabled:opacity-40"
              @click="copyRecap"
            >{{ recapCopy.copied.value ? t('groupIndustry.settlementBlock.recap.copied') : t('groupIndustry.settlementBlock.recap.copyDiscord') }}</button>
            <span class="text-xs text-slate-500">{{ t('groupIndustry.settlementBlock.recap.discordHint') }}</span>
          </p>
          <p v-if="copyFailed" data-testid="settlement-copy-failed" role="alert" class="mt-2 border border-amber-500/30 bg-amber-500/5 px-3 py-1.5 text-xs text-amber-200">{{ t('groupIndustry.settlementBlock.recap.copyFailed') }}</p>
        </div>
      </div>
    </template>

    <p v-if="settlementStore.error" data-testid="settlement-error" role="alert" class="mx-3 mb-2 text-xs text-red-400">{{ settlementStore.error }}</p>

    <div v-if="surplusStore.loading && surplusStore.lines.length === 0" data-testid="settlement-surplus-loading" class="border-t border-slate-800/60 px-3 py-2 text-xs text-slate-500">{{ t('groupIndustry.settlementBlock.surplus.loading') }}</div>
    <div v-else-if="surplusStore.error !== null" data-testid="settlement-surplus-error" role="alert" class="border-t border-slate-800/60 px-3 py-2 text-sm text-red-300">
      {{ t('groupIndustry.settlementBlock.surplus.loadError') }} {{ surplusStore.error }}
    </div>
    <div v-else-if="surplusStore.lines.length > 0" data-testid="settlement-surplus" class="border-t border-slate-800/60 p-3">
      <div class="mb-1.5 flex flex-wrap items-center gap-3">
        <h3 class="font-display text-[11px] font-bold uppercase tracking-[0.12em] text-slate-400">{{ t('groupIndustry.settlementBlock.surplus.title') }}</h3>
        <button
          v-if="canManage && !surplusStore.stored"
          type="button"
          data-testid="settlement-store-surplus"
          class="bg-cyan-600 px-3 py-1 text-xs font-medium text-white transition-colors hover:bg-cyan-500"
          @click="storeSurplus"
        >{{ t('groupIndustry.settlementBlock.surplus.store') }}</button>
        <span v-else-if="surplusStore.stored" data-testid="settlement-surplus-stored" class="text-xs text-emerald-400">
          {{
            t(surplusStore.storedBy === null ? 'groupIndustry.settlementBlock.surplus.storedWithoutName' : 'groupIndustry.settlementBlock.surplus.stored', {
              name: surplusStore.storedBy,
              date: surplusStore.storedAt === null ? '' : formatDate(surplusStore.storedAt),
              value: countedSurplusValue === null ? t('groupIndustry.settlementBlock.surplus.unknownValue') : formatIsk(countedSurplusValue),
            })
          }}
        </span>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left font-display text-[11px] font-bold uppercase tracking-[0.12em] text-slate-500">
              <th class="py-1 pr-3">{{ t('groupIndustry.settlementBlock.surplus.type') }}</th>
              <th class="px-3 py-1 text-right">{{ t('groupIndustry.settlementBlock.surplus.quantity') }}</th>
              <th class="py-1 pl-3 text-right">{{ t('groupIndustry.settlementBlock.surplus.value') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="line in surplusStore.lines" :key="`${line.source}-${line.lineId}`" :data-testid="`settlement-surplus-row-${line.lineId}`" class="border-t border-slate-800/40">
              <td class="max-w-[18rem] truncate py-1 pr-3 text-slate-100" :title="line.typeName">{{ line.typeName }}</td>
              <td class="px-3 py-1 text-right tabular-nums text-amber-300">{{ formatNumber(line.surplusQuantity, 0) }}</td>
              <td :data-testid="`settlement-surplus-value-${line.lineId}`" :data-known="String(lineValueKnown(line))" class="py-1 pl-3 text-right tabular-nums text-slate-300">
                <span v-if="line.source === 'production'" class="text-xs text-slate-500">{{ t('groupIndustry.settlementBlock.surplus.notValued') }}</span>
                <UnknownValue v-else-if="line.surplusValue === null" />
                <template v-else>{{ formatIsk(line.surplusValue) }}</template>
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="border-t border-slate-700">
              <td class="py-1 pr-3 font-semibold text-slate-300" colspan="2">{{ t('groupIndustry.settlementBlock.surplus.total') }}</td>
              <td data-testid="settlement-surplus-total" class="py-1 pl-3 text-right font-semibold tabular-nums text-slate-100">
                <UnknownValue v-if="surplusStore.totalValue === null" />
                <template v-else>{{ formatIsk(surplusStore.totalValue) }}</template>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </section>
</template>
