<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMiningOperationStore, type MiningOperationRecurrence } from '@/stores/miningOperation'
import { formatEveClock, formatEveDateTime } from '@/views/MiningOperation/miningOperationTime'

const { t, locale } = useI18n()
const store = useMiningOperationStore()

const pendingStopId = ref<string | null>(null)
const stopping = ref(false)
const failure = ref<string | null>(null)

function weekdayOf(isoDateTime: string): string {
  return new Intl.DateTimeFormat(locale.value, { weekday: 'long', timeZone: 'UTC' }).format(new Date(isoDateTime))
}

function ruleOf(recurrence: MiningOperationRecurrence): string {
  if (recurrence.kind === 'chunk') {
    return t('miningOperation.recurrence.rule.chunk', { refinery: recurrence.structureName ?? recurrence.structureId })
  }
  const reference = recurrence.nextOccurrenceAt
  if (reference === null) return t('miningOperation.recurrence.rule.weeklyUnknown')
  return t('miningOperation.recurrence.rule.weekly', { day: weekdayOf(reference), time: formatEveClock(reference) })
}

function nextText(recurrence: MiningOperationRecurrence): string {
  return recurrence.nextOccurrenceAt === null
    ? t('miningOperation.recurrence.nextChunk')
    : `${formatEveDateTime(recurrence.nextOccurrenceAt)} EVE`
}

function askStop(recurrenceId: string): void {
  failure.value = null
  pendingStopId.value = recurrenceId
}

async function confirmStop(recurrenceId: string): Promise<void> {
  stopping.value = true
  failure.value = null
  try {
    await store.stopRecurrence(recurrenceId)
    pendingStopId.value = null
  } catch (cause) {
    failure.value = cause instanceof Error ? cause.message : String(cause)
  } finally {
    stopping.value = false
  }
}

onMounted(() => {
  void store.fetchRecurrences()
})
</script>

<template>
  <section class="eve-card p-5" :aria-label="t('miningOperation.recurrence.title')">
    <div class="mb-3 flex items-center justify-between gap-3">
      <h2 class="font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.recurrence.title') }}</h2>
      <span class="rounded-sm bg-slate-800 px-1.5 py-0.5 font-mono text-xs text-slate-300">{{ store.recurrences.length }}</span>
    </div>

    <p v-if="store.recurrences.length === 0" class="text-sm text-slate-400">{{ t('miningOperation.recurrence.empty') }}</p>

    <ul v-else class="divide-y divide-slate-800">
      <li v-for="recurrence in store.recurrences" :key="recurrence.id" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
        <div class="min-w-0 flex-1">
          <p class="flex items-baseline gap-2 font-display font-semibold text-slate-100">
            <span class="text-violet-300" aria-hidden="true">↻</span>
            <span class="min-w-0 [overflow-wrap:anywhere]">{{ recurrence.label }}</span>
          </p>
          <p class="mt-1 text-xs text-slate-400 [overflow-wrap:anywhere]">{{ ruleOf(recurrence) }}</p>
          <p class="mt-0.5 text-xs text-slate-400">
            {{ t('miningOperation.recurrence.next') }}
            <span class="font-mono text-cyan-200">{{ nextText(recurrence) }}</span>
          </p>
        </div>

        <div v-if="pendingStopId === recurrence.id" class="flex flex-wrap items-center justify-end gap-2" role="group" :aria-label="t('miningOperation.recurrence.stopConfirm')">
          <span class="text-xs text-amber-200">{{ t('miningOperation.recurrence.stopConfirm') }}</span>
          <button
            type="button"
            data-testid="confirm-stop-recurrence"
            :disabled="stopping"
            class="rounded-md bg-red-600 px-3 py-1 text-xs font-medium text-white hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-50"
            @click="confirmStop(recurrence.id)"
          >{{ t('miningOperation.recurrence.stopConfirmAction') }}</button>
          <button
            type="button"
            :disabled="stopping"
            class="rounded-md border border-slate-700 px-3 py-1 text-xs text-slate-300 hover:bg-slate-800 disabled:opacity-50"
            @click="pendingStopId = null"
          >{{ t('common.actions.cancel') }}</button>
        </div>
        <button
          v-else
          type="button"
          data-testid="stop-recurrence"
          class="rounded-md border border-slate-600 px-3 py-1 text-xs text-slate-200 hover:bg-slate-800"
          @click="askStop(recurrence.id)"
        >{{ t('miningOperation.recurrence.stop') }}</button>
      </li>
    </ul>

    <p v-if="failure !== null" class="mt-3 text-sm text-red-400" role="alert">{{ failure }}</p>
  </section>
</template>
