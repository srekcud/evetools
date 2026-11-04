<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { MiningLedgerCharacter, MiningLedgerParticipant, MiningLedgerSnapshotStatus } from '@/stores/miningOperation'
import { formatEveClock } from '@/views/MiningOperation/miningOperationTime'

const UNREAD_SNAPSHOT_STATUSES: MiningLedgerSnapshotStatus[] = ['reconnect_required', 'failed', 'out_of_window']
const SETTLED_SNAPSHOT_STATUSES: MiningLedgerSnapshotStatus[] = ['taken', ...UNREAD_SNAPSHOT_STATUSES]

defineProps<{
  participants: MiningLedgerParticipant[]
}>()

const emit = defineEmits<{
  retry: [operationCharacterId: string]
}>()

const { t } = useI18n()

function participantName(participant: MiningLedgerParticipant): string {
  return participant.characterName ?? t('miningOperation.unknownPilot')
}

function takenStarts(participant: MiningLedgerParticipant): number {
  return participant.characters.filter(character => character.startSnapshot?.status === 'taken').length
}

function charactersWithUnreadStart(participant: MiningLedgerParticipant): MiningLedgerCharacter[] {
  return participant.characters.filter(character => character.startSnapshot !== null && UNREAD_SNAPSHOT_STATUSES.includes(character.startSnapshot.status))
}

function unreadStartText(character: MiningLedgerCharacter): string {
  const status = character.startSnapshot?.status
  if (status === 'reconnect_required') return t('miningOperation.snapshot.reconnectStart', { name: character.name })
  return `${character.name} · ${t(status === 'failed' ? 'miningOperation.snapshot.failed' : 'miningOperation.snapshot.outOfWindow')}`
}

function endSnapshotsOf(participant: MiningLedgerParticipant) {
  return participant.characters.flatMap(character => (character.endSnapshot === null ? [] : [character.endSnapshot]))
}

function departureText(participant: MiningLedgerParticipant, departedAt: string): string {
  const endSnapshots = endSnapshotsOf(participant)
  if (endSnapshots.every(snapshot => SETTLED_SNAPSHOT_STATUSES.includes(snapshot.status))) {
    return t('miningOperation.snapshot.departedRead', { time: formatEveClock(departedAt) })
  }
  const readyTimes = endSnapshots.flatMap(snapshot => (snapshot.status === 'waiting' && snapshot.readyAt !== null ? [snapshot.readyAt] : []))
  const ready = readyTimes.length === 0
    ? t('miningOperation.closing.computingReadyAt')
    : formatEveClock(new Date(Math.max(...readyTimes.map(readyAt => Date.parse(readyAt)))).toISOString())
  return t('miningOperation.snapshot.departedWaiting', { time: formatEveClock(departedAt), ready })
}
</script>

<template>
  <ul class="divide-y divide-slate-800">
    <li v-for="participant in participants" :key="participant.memberId" class="py-3">
      <div class="flex flex-wrap items-center gap-2">
        <span class="min-w-0 max-w-full truncate font-display text-base font-semibold text-slate-100" :title="participantName(participant)">{{ participantName(participant) }}</span>
        <span
          class="shrink-0 rounded-sm border px-1.5 py-0.5 font-mono text-xs"
          :class="charactersWithUnreadStart(participant).length === 0 ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300' : 'border-amber-500/40 bg-amber-500/10 text-amber-300'"
        >{{ t(charactersWithUnreadStart(participant).length === 0 ? 'miningOperation.snapshot.startOk' : 'miningOperation.snapshot.startMissing', { taken: takenStarts(participant), total: participant.characters.length }) }}</span>
        <span v-if="participant.departedAt !== null" class="shrink-0 font-mono text-xs text-slate-400">{{ departureText(participant, participant.departedAt) }}</span>
      </div>
      <ul class="mt-2 space-y-1">
        <li v-for="character in participant.characters" :key="character.id" class="flex flex-wrap items-baseline gap-x-2 text-xs" data-testid="snapshot-character">
          <span class="min-w-0 font-mono text-slate-300 [overflow-wrap:anywhere]">{{ character.name }}</span>
          <span
            class="min-w-0 font-mono [overflow-wrap:anywhere]"
            :class="character.shipName === null ? 'text-slate-500' : 'text-cyan-200'"
            data-testid="snapshot-character-ship"
          >{{ character.shipName ?? t('miningOperation.snapshot.shipUnknown') }}</span>
        </li>
      </ul>
      <p
        v-for="character in charactersWithUnreadStart(participant)"
        :key="character.id"
        class="mt-2 flex flex-wrap items-center gap-2 rounded-sm border border-amber-500/30 bg-amber-500/5 px-3 py-1.5 text-xs text-amber-200"
      >
        <span class="min-w-0 truncate" :title="character.name">{{ unreadStartText(character) }}</span>
        <router-link v-if="character.startSnapshot?.status === 'reconnect_required'" to="/characters" class="shrink-0 font-medium text-cyan-400 underline hover:text-cyan-300">{{ t('nav.characters') }}</router-link>
        <button v-if="character.canRetry" type="button" class="shrink-0 rounded-md border border-amber-500/40 px-2 py-0.5 text-xs text-amber-200 hover:bg-amber-500/10" @click="emit('retry', character.id)">{{ t('miningOperation.snapshot.retry') }}</button>
      </p>
    </li>
  </ul>
</template>
