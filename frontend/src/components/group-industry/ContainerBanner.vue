<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { ContainerVerificationStatus } from '@/stores/group-industry/types'
import { useSyncStore } from '@/stores/sync'

const { t } = useI18n()
const { formatNumber, formatDateTime } = useFormatters()
const projectStore = useGroupProjectStore()
const syncStore = useSyncStore()

const PROBLEM_STATUSES: readonly ContainerVerificationStatus[] = [
  'container_not_found',
  'container_in_hidden_division',
  'corporation_assets_stale',
  'unknown',
]

const props = defineProps<{
  containerName: string
  projectId: string
  canVerify?: boolean
}>()

const isVerifying = ref(false)
const isRefreshing = ref(false)
const refreshError = ref<string | null>(null)

const containerCheckProblem = computed(() =>
  projectStore.containerVerification.find(line => PROBLEM_STATUSES.includes(line.status)),
)

const assetsSyncedAt = computed(() =>
  projectStore.containerVerification.find(line => line.assetsSyncedAt !== null)?.assetsSyncedAt ?? null,
)

const approvedGapLines = computed(() => {
  const approvedByBomItemId = new Map(
    projectStore.bomItems.map(item => [item.id, Math.min(item.fulfilledQuantity, item.requiredQuantity)]),
  )

  return projectStore.containerVerification.flatMap(line => {
    const approvedQuantity = approvedByBomItemId.get(line.bomItemId)
    const containerQuantity = line.containerQuantity
    if (approvedQuantity === undefined || typeof containerQuantity !== 'number' || containerQuantity >= approvedQuantity) {
      return []
    }

    return [{
      bomItemId: line.bomItemId,
      typeName: line.typeName,
      containerQuantity,
      approvedQuantity,
    }]
  })
})

async function verifyAll(): Promise<void> {
  isVerifying.value = true
  try {
    await projectStore.fetchContainerVerification(props.projectId)
  } finally {
    isVerifying.value = false
  }
}

async function refreshAssets(): Promise<void> {
  refreshError.value = null
  isRefreshing.value = true
  try {
    const refresh = await projectStore.refreshCorporationAssets()
    if (refresh.status === 'error') {
      refreshError.value = refresh.error ?? t('groupIndustry.container.refreshFailed')
      isRefreshing.value = false
    } else if (refresh.status === 'completed') {
      isRefreshing.value = false
      await verifyAll()
    }
  } catch {
    refreshError.value = t('groupIndustry.container.refreshFailed')
    isRefreshing.value = false
  }
}

watch(() => syncStore.corporationAssetsProgress?.status, async status => {
  if (!isRefreshing.value) return
  if (status === 'completed') {
    isRefreshing.value = false
    await verifyAll()
  } else if (status === 'error') {
    isRefreshing.value = false
    refreshError.value = t('groupIndustry.container.refreshFailed')
  }
})
</script>

<template>
  <div class="bg-violet-500/5 rounded-xl border border-violet-500/20 p-3 mb-4 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <div class="w-7 h-7 rounded-lg bg-violet-500/12 flex items-center justify-center flex-shrink-0">
        <svg class="w-3.5 h-3.5 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
        </svg>
      </div>
      <div>
        <span class="text-sm text-violet-400 font-medium">Container: "{{ containerName }}"</span>
        <span class="text-xs text-slate-500 ml-2">linked to corp offices</span>
        <p v-if="containerCheckProblem" class="text-xs text-amber-400 mt-1">
          <template v-if="containerCheckProblem.status === 'unknown' && containerCheckProblem.unknownReason !== null">
            {{ t('groupIndustry.container.statuses.unknown') }}: {{ t(`groupIndustry.container.unknownReasons.${containerCheckProblem.unknownReason}`) }}
          </template>
          <template v-else>
            {{ t(`groupIndustry.container.statuses.${containerCheckProblem.status}`) }}
          </template>
        </p>
        <p v-if="assetsSyncedAt !== null" data-testid="container-assets-synced-at" class="text-xs text-slate-500 mt-1">
          {{ t('groupIndustry.container.assetsSyncedAt', { date: formatDateTime(assetsSyncedAt) }) }}
        </p>
        <p v-if="refreshError !== null" data-testid="container-refresh-error" class="text-xs text-red-400 mt-1">
          {{ refreshError }}
        </p>
        <p v-if="projectStore.containerVerificationFailed" class="text-xs text-red-400 mt-1">
          {{ t('groupIndustry.container.verifyFailed') }}
        </p>
        <div v-if="approvedGapLines.length > 0" class="mt-1">
          <p class="text-xs text-amber-400">{{ t('groupIndustry.container.approvedGapTitle') }}</p>
          <ul class="text-xs text-amber-400/80">
            <li v-for="line in approvedGapLines" :key="line.bomItemId">
              {{ t('groupIndustry.container.approvedGapLine', {
                name: line.typeName,
                container: formatNumber(line.containerQuantity, 0),
                approved: formatNumber(line.approvedQuantity, 0),
              }) }}
            </li>
          </ul>
        </div>
      </div>
    </div>
    <div v-if="canVerify" class="flex items-center gap-2">
    <button
      data-testid="container-refresh-assets"
      @click="refreshAssets"
      :disabled="isRefreshing"
      class="px-3 py-1.5 bg-slate-700/30 hover:bg-slate-700/50 rounded-lg text-slate-300 text-xs font-medium border border-slate-600/40 transition-colors disabled:opacity-50"
    >
      {{ isRefreshing ? t('groupIndustry.container.refreshingAssets') : t('groupIndustry.container.refreshAssets') }}
    </button>
    <button
      @click="verifyAll"
      :disabled="isVerifying"
      class="px-3 py-1.5 bg-violet-600/20 hover:bg-violet-600/30 rounded-lg text-violet-400 text-xs font-medium flex items-center gap-1.5 border border-violet-500/25 transition-colors disabled:opacity-50"
    >
      <!-- Spinner when verifying -->
      <svg v-if="isVerifying" class="w-3.5 h-3.5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
      </svg>
      <!-- Check icon when idle -->
      <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      {{ isVerifying ? t('groupIndustry.container.verifying') : t('groupIndustry.container.verifyAll') }}
    </button>
    </div>
  </div>
</template>
