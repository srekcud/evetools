<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import MiningStatusBadge from '@/components/mining-operation/MiningStatusBadge.vue'
import NewMiningOperationModal from '@/components/mining-operation/NewMiningOperationModal.vue'
import { useTabQuery } from '@/composables/useTabQuery'
import { useKnownCorporation } from '@/composables/useKnownCorporation'
import { useMiningOperationStore, type MiningOperation } from '@/stores/miningOperation'
import { useSyncStore, type MiningOperationEvent } from '@/stores/sync'
import { formatWhole } from './miningOperationQuantity'
import { formatEveDateTime } from './miningOperationTime'

type ListTab = 'mine' | 'available' | 'archive'

const LIST_TABS = ['mine', 'available', 'archive'] as const satisfies readonly ListTab[]
const ARCHIVING_ACTIONS = ['operation_settled', 'operation_completed']

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useMiningOperationStore()
const syncStore = useSyncStore()

const activeTab = useTabQuery<ListTab>(LIST_TABS, 'mine')
const loading = ref(true)
const showCreation = ref(false)

const hasKnownCorporation = useKnownCorporation()
const operationDeleted = computed(() => route.query.notice === 'deleted')
const OPERATIONS_BY_TAB = {
  mine: () => store.myOperations,
  available: () => store.availableOperations,
  archive: () => store.archivedOperations,
} as const satisfies Record<ListTab, () => MiningOperation[]>

const shownOperations = computed(() => OPERATIONS_BY_TAB[activeTab.value]())
const listedOperationIds = computed(() => [...store.myOperations, ...store.archivedOperations].map(operation => operation.id))
const handledEvents = new Map<string, MiningOperationEvent>()

function tabCount(tab: ListTab): number {
  return OPERATIONS_BY_TAB[tab]().length
}

function amountText(amount: number | null): string {
  return amount === null ? t('miningOperation.quantities.unknown') : formatWhole(amount, locale.value)
}

interface ShareCell {
  kind: 'notParticipant' | 'share' | 'value'
  amount: number | null
}

function totalValueOf(operation: MiningOperation): number | null {
  return operation.archiveSummary === null ? null : operation.archiveSummary.totalValue
}

function shareCellOf(operation: MiningOperation): ShareCell {
  const summary = operation.archiveSummary
  if (summary === null || summary.myMinedValue === null) return { kind: 'notParticipant', amount: null }
  return summary.myShare === null ? { kind: 'value', amount: summary.myMinedValue } : { kind: 'share', amount: summary.myShare }
}

function paymentsText(operation: MiningOperation): string {
  const summary = operation.archiveSummary
  if (summary === null) return t('miningOperation.quantities.unknown')
  return summary.paymentsCount === 0
    ? t('miningOperation.archive.nothingToSettle')
    : t('miningOperation.archive.paymentsCount', { paid: summary.paidPaymentsCount, total: summary.paymentsCount })
}

function rememberCurrentEvents(): void {
  for (const operationId of listedOperationIds.value) {
    const event = syncStore.getMiningOperationEvent(operationId)
    if (event !== null) handledEvents.set(operationId, event)
  }
}

async function loadLists(): Promise<void> {
  await Promise.all([store.fetchMine(), store.fetchAvailable(), store.fetchArchive()])
  rememberCurrentEvents()
}

async function reloadMineAndArchive(): Promise<void> {
  await Promise.all([store.fetchMine(), store.fetchArchive()])
  rememberCurrentEvents()
}

function hasNewArchivingEvent(): boolean {
  return listedOperationIds.value.some(operationId => {
    const event = syncStore.getMiningOperationEvent(operationId)
    return event !== null && ARCHIVING_ACTIONS.includes(event.action) && handledEvents.get(operationId) !== event
  })
}

watch(
  () => listedOperationIds.value.map(operationId => syncStore.getMiningOperationEvent(operationId)),
  () => {
    if (hasNewArchivingEvent()) reloadMineAndArchive()
  },
)

function detailTarget(operation: MiningOperation) {
  return { name: 'mining-operation-detail', params: { id: operation.id } }
}

function joinTarget(joinCode: string) {
  return { name: 'mining-operation-join', params: { code: joinCode } }
}

function openCreatedOperation(operationId: string): void {
  showCreation.value = false
  router.push({ name: 'mining-operation-detail', params: { id: operationId } })
}

function openDetail(operation: MiningOperation): void {
  router.push(detailTarget(operation))
}

onMounted(async () => {
  await loadLists()
  loading.value = false
})
</script>

<template>
  <MainLayout>
    <div class="mx-auto max-w-5xl px-6 py-8">
      <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="font-display text-2xl font-bold text-slate-100">{{ t('miningOperation.title') }}</h1>
        <button
          type="button"
          class="flex items-center gap-2 rounded-md bg-cyan-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-cyan-500"
          @click="showCreation = true"
        >{{ t('miningOperation.list.new') }}</button>
      </header>

      <p v-if="operationDeleted" class="mb-4 rounded-md border border-slate-700 bg-slate-900 px-4 py-2 text-sm text-slate-200" role="status">{{ t('miningOperation.detail.deleted') }}</p>

      <div class="mb-6 flex gap-6 border-b border-slate-800" role="tablist">
        <button
          v-for="tab in LIST_TABS"
          :key="tab"
          type="button"
          role="tab"
          :aria-selected="activeTab === tab"
          class="border-b-2 px-1 pb-3 font-display text-base font-semibold tracking-wide transition-colors"
          :class="activeTab === tab ? 'border-cyan-400 text-slate-50' : 'border-transparent text-slate-400 hover:text-slate-200'"
          @click="activeTab = tab"
        >
          {{ t(`miningOperation.tabs.${tab}`) }}
          <span class="ml-1.5 rounded-sm bg-slate-800 px-1.5 py-0.5 font-mono text-xs text-slate-300">{{ tabCount(tab) }}</span>
        </button>
      </div>

      <div v-if="loading" class="flex justify-center py-16">
        <LoadingSpinner size="xl" class="text-cyan-400" />
      </div>

      <p v-else-if="store.error !== null && shownOperations.length === 0" class="eve-card p-6 text-sm text-red-400" role="alert">{{ store.error }}</p>

      <p v-else-if="activeTab === 'available' && !hasKnownCorporation" class="eve-card p-6 text-sm text-slate-300">{{ t('miningOperation.list.noCorporation') }}</p>

      <template v-else-if="activeTab === 'archive'">
        <p class="mb-4 text-sm text-slate-400">{{ t('miningOperation.archive.hint') }}</p>
        <p v-if="shownOperations.length === 0" class="eve-card p-6 text-sm text-slate-300">{{ t('miningOperation.archive.empty') }}</p>
        <div v-else class="eve-card overflow-x-auto p-2">
          <table class="w-full text-left text-sm">
            <thead>
              <tr class="border-b border-slate-800 font-mono text-xs uppercase tracking-wider text-slate-400">
                <th class="px-3 py-2 font-medium">{{ t('miningOperation.archive.columns.operation') }}</th>
                <th class="px-3 py-2 font-medium">{{ t('miningOperation.archive.columns.archivedAt') }}</th>
                <th class="px-3 py-2 font-medium">{{ t('miningOperation.archive.columns.system') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('miningOperation.archive.columns.participants') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('miningOperation.archive.columns.totalValue') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('miningOperation.archive.columns.myShare') }}</th>
                <th class="px-3 py-2 font-medium">{{ t('miningOperation.archive.columns.payments') }}</th>
                <th class="px-3 py-2 font-medium">{{ t('miningOperation.archive.columns.status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="operation in shownOperations" :key="operation.id" class="cursor-pointer border-b border-slate-800/60 hover:bg-slate-800/40" @click="openDetail(operation)">
                <td class="max-w-56 px-3 py-2.5">
                  <router-link :to="detailTarget(operation)" class="block text-slate-100 hover:text-cyan-200" @click.stop>
                    <span class="block truncate font-display font-semibold" :title="operation.label">{{ operation.label }}</span>
                  </router-link>
                </td>
                <td class="px-3 py-2.5 font-mono text-xs text-cyan-200">{{ operation.archivedAt === null ? t('miningOperation.quantities.unknown') : formatEveDateTime(operation.archivedAt) }}</td>
                <td class="max-w-40 truncate px-3 py-2.5 text-slate-300" :title="operation.solarSystemName ?? ''">{{ operation.solarSystemName ?? t('miningOperation.start.systemNone') }}</td>
                <td class="px-3 py-2.5 text-right font-mono text-slate-300">{{ operation.participantsCount }}</td>
                <td class="px-3 py-2.5 text-right font-mono" :class="totalValueOf(operation) === null ? 'text-amber-300' : 'text-slate-100'">{{ amountText(totalValueOf(operation)) }}</td>
                <td class="px-3 py-2.5 text-right font-mono">
                  <span v-if="shareCellOf(operation).kind === 'notParticipant'" class="text-slate-400">{{ t('miningOperation.archive.notParticipant') }}</span>
                  <template v-else>
                    <span class="block text-slate-100">{{ amountText(shareCellOf(operation).amount) }}</span>
                    <span v-if="shareCellOf(operation).kind === 'value'" class="block text-xs text-slate-400">{{ t('miningOperation.archive.myValue') }}</span>
                  </template>
                </td>
                <td class="px-3 py-2.5 font-mono text-xs text-slate-300">{{ paymentsText(operation) }}</td>
                <td class="px-3 py-2.5"><MiningStatusBadge :status="operation.status" /></td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <p v-else-if="shownOperations.length === 0" class="eve-card p-6 text-sm text-slate-300">
        {{ activeTab === 'mine' ? t('miningOperation.list.emptyMine') : t('miningOperation.list.emptyAvailable') }}
      </p>

      <ul v-else class="grid gap-4 md:grid-cols-2">
        <li v-for="operation in shownOperations" :key="operation.id" class="eve-card flex flex-col gap-3 p-5">
          <div class="flex items-start justify-between gap-3">
            <h2 class="min-w-0 truncate font-display text-lg font-semibold text-slate-100" :title="operation.label">{{ operation.label }}</h2>
            <MiningStatusBadge :status="operation.status" />
          </div>
          <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="rounded-sm border border-slate-600 bg-slate-800 px-2 py-0.5 font-mono text-slate-300">{{ t(`miningOperation.visibility.${operation.visibility}`) }}</span>
            <span class="font-mono text-cyan-200">{{ formatEveDateTime(operation.plannedStartAt) }} EVE</span>
            <span class="text-slate-400">{{ t('miningOperation.list.participants', { count: operation.participantsCount }) }}</span>
          </div>
          <div class="mt-auto flex justify-end">
            <router-link
              v-if="activeTab === 'mine'"
              :to="detailTarget(operation)"
              class="rounded-md border border-slate-600 px-4 py-1.5 text-sm text-slate-100 hover:bg-slate-800"
            >{{ t('miningOperation.list.open') }}</router-link>
            <router-link
              v-else-if="operation.joinCode !== null"
              :to="joinTarget(operation.joinCode)"
              class="rounded-md bg-cyan-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-cyan-500"
            >{{ t('miningOperation.list.join') }}</router-link>
          </div>
        </li>
      </ul>
    </div>

    <NewMiningOperationModal :show="showCreation" @close="showCreation = false" @open="openCreatedOperation" />
  </MainLayout>
</template>
