<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MiningLedgerCharacter, MiningLedgerParticipant, MiningLedgerSnapshot, MiningOperationLedger } from '@/stores/miningOperation'
import { formatCount } from '@/views/MiningOperation/miningOperationQuantity'
import { formatCountdown, formatEveClock } from '@/views/MiningOperation/miningOperationTime'

const PERCENT = 100
const UNREAD_SNAPSHOT_STATUSES = ['reconnect_required', 'failed', 'out_of_window']

const props = defineProps<{
  ledger: MiningOperationLedger
  now: number
}>()

const emit = defineEmits<{
  retry: [operationCharacterId: string]
}>()

const { t, locale } = useI18n()

const waitingForEndLedger = computed(() => props.ledger.endSnapshotsSettled < props.ledger.endSnapshotsTotal)
const progressPercent = computed(() => (props.ledger.endSnapshotsTotal === 0 ? 0 : Math.round(props.ledger.endSnapshotsSettled * PERCENT / props.ledger.endSnapshotsTotal)))
const readyAt = computed(() => props.ledger.endSnapshotsReadyAt)
const readTimeKnown = computed(() => readyAt.value !== null && !props.ledger.endSnapshotsPending)

function participantName(participant: MiningLedgerParticipant): string {
  return participant.characterName ?? t('miningOperation.unknownPilot')
}

function snapshotShown(character: MiningLedgerCharacter): MiningLedgerSnapshot | null {
  const start = character.startSnapshot
  if (character.endSnapshot === null && start !== null && UNREAD_SNAPSHOT_STATUSES.includes(start.status)) return start
  return character.endSnapshot
}

function snapshotText(snapshot: MiningLedgerSnapshot | null): string {
  if (snapshot === null) return '–'
  switch (snapshot.status) {
    case 'scheduling':
      return t('miningOperation.snapshot.queued')
    case 'waiting':
      return snapshot.readyAt === null ? t('miningOperation.snapshot.queued') : t('miningOperation.snapshot.readyAt', { time: formatEveClock(snapshot.readyAt) })
    case 'taken':
      return snapshot.takenAt === null ? '–' : t('miningOperation.snapshot.read', { time: formatEveClock(snapshot.takenAt) })
    case 'reconnect_required':
      return t('miningOperation.snapshot.reconnect')
    case 'failed':
      return t('miningOperation.snapshot.failed')
    case 'out_of_window':
      return t('miningOperation.snapshot.outOfWindow')
  }
}

function snapshotClass(snapshot: MiningLedgerSnapshot | null): string {
  if (snapshot === null) return 'text-slate-500'
  if (snapshot.status === 'taken') return 'text-emerald-300'
  return UNREAD_SNAPSHOT_STATUSES.includes(snapshot.status) ? 'text-amber-300' : 'text-slate-300'
}

function quantityText(character: MiningLedgerCharacter): string {
  if (character.state === 'unknown') return t('miningOperation.quantities.unknown')
  if (character.state === 'measuring' || character.totalQuantity === null) return '–'
  return character.totalQuantity === 0 ? formatCount(0, locale.value) : t('miningOperation.quantities.units', { count: formatCount(character.totalQuantity, locale.value) })
}
</script>

<template>
  <div class="space-y-5">
    <section v-if="waitingForEndLedger" class="eve-card border-amber-500/30 p-5" role="status">
      <h2 class="mb-2 font-display text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">{{ t('miningOperation.closing.waitingTitle') }}</h2>
      <p v-if="readTimeKnown && ledger.endedAt !== null && readyAt !== null" class="text-sm text-slate-200">{{ t('miningOperation.closing.waitingText', { end: formatEveClock(ledger.endedAt), ready: formatEveClock(readyAt) }) }}</p>
      <p class="text-sm text-slate-400">{{ t('miningOperation.closing.nothingEstimated') }}</p>
      <p v-if="readTimeKnown && readyAt !== null" class="mt-3 font-mono text-sm text-cyan-200">{{ t('miningOperation.closing.readIn') }} <span class="text-lg">{{ formatCountdown(Date.parse(readyAt) - now) }}</span></p>
      <p v-else class="mt-3 font-mono text-sm text-slate-300">{{ t('miningOperation.closing.computingReadyAt') }}</p>
    </section>

    <section class="eve-card p-5">
      <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.closing.progress') }}</h2>
        <p class="font-mono text-xs text-slate-400">{{ t('miningOperation.closing.progressCount', { settled: ledger.endSnapshotsSettled, total: ledger.endSnapshotsTotal }) }}</p>
      </div>
      <div class="h-1.5 overflow-hidden rounded-full bg-slate-800" role="progressbar" :aria-valuenow="progressPercent" aria-valuemin="0" aria-valuemax="100">
        <div class="h-full bg-cyan-500 transition-all" :style="{ width: `${progressPercent}%` }"></div>
      </div>
    </section>

    <section class="eve-card p-5">
      <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.closing.snapshots') }}</h2>
        <p class="text-xs text-slate-400">{{ t('miningOperation.closing.snapshotsHint') }}</p>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead>
            <tr class="border-b border-slate-800 font-mono text-xs uppercase tracking-wider text-slate-400">
              <th class="py-2 pr-3 font-medium">{{ t('miningOperation.closing.columns.character') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('miningOperation.closing.columns.participant') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('miningOperation.closing.columns.endSnapshot') }}</th>
              <th class="py-2 text-right font-medium">{{ t('miningOperation.closing.columns.quantity') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="participant in ledger.participants" :key="participant.memberId">
              <tr v-for="character in participant.characters" :key="character.id" class="border-b border-slate-800/60">
                <td class="max-w-40 truncate py-2 pr-3 text-slate-100" :title="character.name">{{ character.name }}</td>
                <td class="max-w-40 truncate py-2 pr-3 text-slate-300" :title="participantName(participant)">{{ participantName(participant) }}</td>
                <td class="py-2 pr-3 font-mono text-xs" :class="snapshotClass(snapshotShown(character))">
                  <span>{{ snapshotText(snapshotShown(character)) }}</span>
                  <button v-if="character.canRetry" type="button" class="ml-2 rounded-md border border-amber-500/40 px-2 py-0.5 text-xs text-amber-200 hover:bg-amber-500/10" @click="emit('retry', character.id)">{{ t('miningOperation.snapshot.retry') }}</button>
                </td>
                <td class="py-2 text-right font-mono text-xs" :class="character.state === 'unknown' ? 'text-amber-300' : 'text-slate-200'">{{ quantityText(character) }}</td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
