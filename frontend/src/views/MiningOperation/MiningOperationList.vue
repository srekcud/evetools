<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import MiningStatusBadge from '@/components/mining-operation/MiningStatusBadge.vue'
import NewMiningOperationModal from '@/components/mining-operation/NewMiningOperationModal.vue'
import { useTabQuery } from '@/composables/useTabQuery'
import { useKnownCorporation } from '@/composables/useKnownCorporation'
import { useMiningOperationStore, type MiningOperation } from '@/stores/miningOperation'
import { formatEveDateTime } from './miningOperationTime'

type ListTab = 'mine' | 'available'

const LIST_TABS = ['mine', 'available'] as const satisfies readonly ListTab[]

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useMiningOperationStore()

const activeTab = useTabQuery<ListTab>(LIST_TABS, 'mine')
const loading = ref(true)
const showCreation = ref(false)

const hasKnownCorporation = useKnownCorporation()
const operationDeleted = computed(() => route.query.notice === 'deleted')
const shownOperations = computed(() => (activeTab.value === 'mine' ? store.myOperations : store.availableOperations))

function tabCount(tab: ListTab): number {
  return tab === 'mine' ? store.myOperations.length : store.availableOperations.length
}

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

onMounted(async () => {
  await Promise.all([store.fetchMine(), store.fetchAvailable()])
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
