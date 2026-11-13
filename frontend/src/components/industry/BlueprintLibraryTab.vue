<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import CharacterSyncFailuresAlert from '@/components/industry/CharacterSyncFailuresAlert.vue'
import SyncErrorAlert from '@/components/industry/SyncErrorAlert.vue'
import { useEveImages } from '@/composables/useEveImages'
import { useTypeNames } from '@/composables/useTypeNames'
import { normalizeSearchText } from '@/utils/commandPaletteSearch'
import { useBlueprintsStore } from '@/stores/industry/blueprints'
import { useIndustryStore } from '@/stores/industry'
import type { BlueprintKind, BlueprintLibraryEntry, BlueprintScope, CharacterSyncFailure, ProjectPrefillItem } from '@/stores/industry/types'
import { useSyncStore } from '@/stores/sync'

type ScopeFilter = 'all' | BlueprintScope

const UNKNOWN_TYPE_MARKER = 'unknown_type'
const SYNC_TYPE = 'blueprints'
const FILTERS: ScopeFilter[] = ['all', 'personal', 'corporation']
const KINDS: BlueprintKind[] = ['bpo', 'bpc', 'bpr']
const NOT_APPLICABLE = '—'
const DEFAULT_RUNS = 1
const KIND_BADGE_CLASSES: Record<BlueprintKind, string> = {
  bpo: 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',
  bpr: 'bg-violet-500/10 text-violet-400 border-violet-500/20',
  bpc: 'bg-slate-500/10 text-slate-400 border-slate-500/20',
}

const { t } = useI18n()
const { getBlueprintIconUrl, onImageError } = useEveImages()
const { typeNameLabel } = useTypeNames()
const store = useBlueprintsStore()
const syncStore = useSyncStore()
const industryStore = useIndustryStore()

const filter = ref<ScopeFilter>('all')
const search = ref('')
const selectedKinds = ref<BlueprintKind[]>([...KINDS])
const syncErrors = ref<string[]>([])
const lastSyncFailures = ref<CharacterSyncFailure[]>([])

const normalizedSearch = computed(() => normalizeSearchText(search.value))

const entriesInScope = computed<BlueprintLibraryEntry[]>(() => {
  const blueprints = store.library?.blueprints ?? []
  const inScope = filter.value === 'all' ? blueprints : blueprints.filter(blueprint => blueprint.scope === filter.value)
  if (normalizedSearch.value === '') return inScope
  return inScope.filter(entry => normalizeSearchText(nameOf(entry)).includes(normalizedSearch.value))
})

const entries = computed<BlueprintLibraryEntry[]>(() => entriesInScope.value.filter(entry => selectedKinds.value.includes(entry.kind)))

const countByKind = computed<Record<BlueprintKind, number>>(() => {
  const counts: Record<BlueprintKind, number> = { bpo: 0, bpc: 0, bpr: 0 }
  for (const entry of entriesInScope.value) counts[entry.kind] += entry.count
  return counts
})

const indexByEntry = computed(() => new Map((store.library?.blueprints ?? []).map((entry, index) => [entry, index])))
const selectedIndexes = ref<Set<number>>(new Set())

function isSelectable(entry: BlueprintLibraryEntry): boolean {
  return entry.productTypeId !== null
}

function indexOf(entry: BlueprintLibraryEntry): number {
  const index = indexByEntry.value.get(entry)
  if (index === undefined) throw new Error(`Blueprint ${entry.typeId} is not in the library`)
  return index
}

const selectableEntries = computed(() => entries.value.filter(isSelectable))
const selectedEntries = computed(() => selectableEntries.value.filter(entry => selectedIndexes.value.has(indexOf(entry))))
const allVisibleSelected = computed(() => selectableEntries.value.length > 0 && selectedEntries.value.length === selectableEntries.value.length)

function setSelected(entry: BlueprintLibraryEntry, selected: boolean): void {
  const next = new Set(selectedIndexes.value)
  if (selected) next.add(indexOf(entry))
  else next.delete(indexOf(entry))
  selectedIndexes.value = next
}

function setAllVisibleSelected(selected: boolean): void {
  selectedIndexes.value = selected ? new Set(selectableEntries.value.map(indexOf)) : new Set()
}

watch(selectableEntries, (visible) => {
  const visibleIndexes = new Set(visible.map(indexOf))
  const kept = [...selectedIndexes.value].filter(index => visibleIndexes.has(index))
  if (kept.length !== selectedIndexes.value.size) selectedIndexes.value = new Set(kept)
})

watch(() => store.library, () => {
  selectedIndexes.value = new Set()
})

function prefillItemOf(entry: BlueprintLibraryEntry): ProjectPrefillItem {
  if (entry.productTypeId === null || entry.productName === null) throw new Error(`Blueprint ${entry.typeId} has no product`)
  return {
    typeId: entry.productTypeId,
    typeName: entry.productName === UNKNOWN_TYPE_MARKER ? typeNameLabel(null, entry.productTypeId) : entry.productName,
    meLevel: entry.me,
    teLevel: entry.te,
    runs: entry.kind === 'bpc' && entry.runs !== null ? entry.runs : DEFAULT_RUNS,
  }
}

function createProject(): void {
  if (selectedEntries.value.length === 0) return
  industryStore.navigationIntent = { target: 'projects', prefillItems: selectedEntries.value.map(prefillItemOf) }
}

function toggleKind(kind: BlueprintKind): void {
  if (!selectedKinds.value.includes(kind)) {
    selectedKinds.value = KINDS.filter(candidate => candidate === kind || selectedKinds.value.includes(candidate))
    return
  }
  if (selectedKinds.value.length === 1) return
  selectedKinds.value = selectedKinds.value.filter(candidate => candidate !== kind)
}

const syncing = computed(() => store.requestingRefresh || syncStore.isLoading(SYNC_TYPE))

const alertMessages = computed(() => [
  ...syncErrors.value,
  ...(store.refreshError ? [store.refreshError] : []),
  ...(store.library && store.error ? [store.error] : []),
])

function nameOf(entry: BlueprintLibraryEntry): string {
  return entry.name === UNKNOWN_TYPE_MARKER ? typeNameLabel(null, entry.typeId) : entry.name
}

function dismissAlerts(): void {
  syncErrors.value = []
  store.clearErrors()
}

watch(() => syncStore.getSyncProgress(SYNC_TYPE), (progress) => {
  if (!progress) return
  if (progress.status === 'completed') {
    const failedCharacters = progress.data?.failedCharacters
    lastSyncFailures.value = Array.isArray(failedCharacters) ? failedCharacters as CharacterSyncFailure[] : []
    store.fetchLibrary()
  } else if (progress.status === 'error') {
    syncErrors.value = [...syncErrors.value, progress.message || t('notifications.syncError')]
  }
})

onMounted(() => {
  store.fetchLibrary()
})
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold text-white">{{ t('industry.blueprints.title') }}</h1>
        <p class="text-sm text-slate-400">{{ t('industry.blueprints.subtitle') }}</p>
      </div>
      <button
        data-testid="blueprints-refresh"
        class="flex items-center gap-2 px-4 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 rounded-lg text-white text-sm font-medium transition-colors"
        :disabled="syncing"
        @click="store.requestRefresh()"
      >
        <svg aria-hidden="true" class="w-4 h-4" :class="{ 'animate-spin': syncing }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        {{ t('industry.blueprints.refresh') }}
      </button>
    </div>

    <SyncErrorAlert
      v-if="alertMessages.length > 0"
      data-testid="blueprints-sync-error"
      :title="t('industry.blueprints.syncErrorTitle', alertMessages.length)"
      :messages="alertMessages"
      @dismiss="dismissAlerts"
    />

    <CharacterSyncFailuresAlert
      v-if="lastSyncFailures.length > 0"
      data-testid="blueprints-sync-failures"
      :title="t('industry.blueprints.syncFailuresTitle', lastSyncFailures.length)"
      :failures="lastSyncFailures"
      @dismiss="lastSyncFailures = []"
    />

    <div v-if="store.loading && !store.library" class="bg-slate-900 rounded-xl border border-slate-800 p-8 flex items-center justify-center text-slate-400 text-sm">
      {{ t('common.actions.loading') }}
    </div>

    <div v-else-if="store.error && !store.library" data-testid="blueprints-load-error" class="bg-slate-900 rounded-xl border border-red-800/30 p-6 text-center">
      <p class="text-red-400 text-sm">{{ store.error }}</p>
      <button
        data-testid="blueprints-retry"
        class="mt-3 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 text-sm rounded-lg transition-colors"
        @click="store.fetchLibrary()"
      >
        {{ t('common.actions.retry') }}
      </button>
    </div>

    <template v-else-if="store.library">
      <div
        v-if="store.library.corporationState === 'no_director'"
        data-testid="blueprints-no-director"
        role="status"
        class="p-3 bg-amber-500/20 border border-amber-500/50 rounded-lg text-sm text-amber-200"
      >
        <p class="font-semibold">{{ t('industry.blueprints.noDirector.title') }}</p>
        <p>{{ t('industry.blueprints.noDirector.detail') }}</p>
      </div>

      <div
        v-if="store.library.corporationState === 'no_visibility'"
        data-testid="blueprints-no-visibility"
        role="status"
        class="p-3 bg-amber-500/20 border border-amber-500/50 rounded-lg text-sm text-amber-200"
      >
        <p class="font-semibold">{{ t('industry.blueprints.noVisibility.title') }}</p>
        <p>{{ t('industry.blueprints.noVisibility.detail') }}</p>
      </div>

      <div class="bg-slate-900 rounded-xl border border-slate-800">
        <div class="flex flex-wrap items-center justify-between gap-3 p-4 border-b border-slate-800">
          <h2 class="text-base font-semibold text-white">{{ t('industry.blueprints.library') }}</h2>
          <div class="flex flex-wrap items-center gap-3">
            <button
              type="button"
              data-testid="blueprints-create-project"
              class="px-3 py-1.5 bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 disabled:hover:bg-cyan-600 rounded-lg text-white text-sm font-medium transition-colors"
              :disabled="selectedEntries.length === 0"
              @click="createProject"
            >{{ t('industry.blueprints.createProject', { count: selectedEntries.length }) }}</button>
            <input
              v-model="search"
              data-testid="blueprints-search"
              type="search"
              :placeholder="t('industry.blueprints.searchPlaceholder')"
              :aria-label="t('industry.blueprints.searchLabel')"
              class="flex-1 min-w-40 max-w-xs px-3 py-1.5 bg-slate-800 border border-slate-700 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-hidden focus:border-cyan-500"
            />
            <div
              data-testid="blueprints-type-filter"
              class="inline-flex rounded-lg border border-slate-700 overflow-hidden"
              role="group"
              :aria-label="t('industry.blueprints.typeFilter')"
            >
              <button
                v-for="kind in KINDS"
                :key="kind"
                :data-testid="`blueprints-type-${kind}`"
                :aria-pressed="selectedKinds.includes(kind)"
                class="px-3 py-1.5 text-sm transition-colors"
                :class="selectedKinds.includes(kind) ? 'bg-cyan-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                @click="toggleKind(kind)"
              >
                {{ t(`industry.blueprints.kind.${kind}`) }}
                <span class="ml-1 font-mono text-xs opacity-80">{{ countByKind[kind] }}</span>
              </button>
            </div>
            <div class="inline-flex rounded-lg border border-slate-700 overflow-hidden" role="group" :aria-label="t('industry.blueprints.library')">
              <button
                v-for="option in FILTERS"
                :key="option"
                :data-testid="`blueprints-filter-${option}`"
                :aria-pressed="filter === option"
                class="px-3 py-1.5 text-sm transition-colors"
                :class="filter === option ? 'bg-cyan-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                @click="filter = option"
              >
                {{ t(`industry.blueprints.filter.${option}`) }}
              </button>
            </div>
          </div>
        </div>

        <p v-if="entries.length === 0 && normalizedSearch !== ''" data-testid="blueprints-search-empty" class="p-6 text-sm text-slate-500">
          {{ t('industry.blueprints.searchEmpty') }}
        </p>

        <p v-else-if="entries.length === 0" data-testid="blueprints-empty" class="p-6 text-sm text-slate-500">
          {{ t('industry.blueprints.empty') }}
        </p>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase text-slate-500">
                <th class="px-4 py-2 w-8">
                  <input
                    type="checkbox"
                    data-testid="blueprints-select-all"
                    :aria-label="t('industry.blueprints.selectAll')"
                    :checked="allVisibleSelected"
                    :disabled="selectableEntries.length === 0"
                    class="accent-cyan-500"
                    @change="setAllVisibleSelected(($event.target as HTMLInputElement).checked)"
                  />
                </th>
                <th class="px-4 py-2">{{ t('industry.blueprints.columns.blueprint') }}</th>
                <th class="px-4 py-2">{{ t('industry.blueprints.columns.kind') }}</th>
                <th class="px-4 py-2 text-right">{{ t('industry.blueprints.columns.efficiency') }}</th>
                <th class="px-4 py-2 text-right">{{ t('industry.blueprints.columns.runs') }}</th>
                <th class="px-4 py-2">{{ t('industry.blueprints.columns.where') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(entry, index) in entries"
                :key="`${entry.typeId}-${entry.kind}-${entry.scope}-${entry.locationId}-${index}`"
                data-testid="blueprint-row"
                class="border-t border-slate-800"
              >
                <td class="px-4 py-2">
                  <input
                    type="checkbox"
                    data-testid="blueprint-select"
                    :aria-label="t('industry.blueprints.selectRow', { name: nameOf(entry) })"
                    :title="isSelectable(entry) ? undefined : t('industry.blueprints.noProduct')"
                    :checked="selectedIndexes.has(indexOf(entry))"
                    :disabled="!isSelectable(entry)"
                    class="accent-cyan-500"
                    @change="setSelected(entry, ($event.target as HTMLInputElement).checked)"
                  />
                </td>
                <td class="px-4 py-2">
                  <div class="flex items-center gap-2 min-w-0">
                    <img :src="getBlueprintIconUrl(entry.typeId, entry.kind)" alt="" class="w-6 h-6 rounded-sm" @error="onImageError" />
                    <span class="truncate text-slate-200">{{ nameOf(entry) }}</span>
                    <span v-if="entry.count > 1" class="text-xs text-slate-500">×{{ entry.count }}</span>
                    <span
                      v-if="entry.usedByRunningJob"
                      data-testid="blueprint-in-use"
                      class="shrink-0 px-2 py-0.5 rounded-sm text-xs border bg-amber-500/10 text-amber-400 border-amber-500/20"
                    >{{ t('industry.blueprints.inUse') }}</span>
                  </div>
                </td>
                <td class="px-4 py-2">
                  <span
                    class="px-2 py-0.5 rounded-sm text-xs border"
                    :class="KIND_BADGE_CLASSES[entry.kind]"
                  >{{ t(`industry.blueprints.kind.${entry.kind}`) }}</span>
                </td>
                <td class="px-4 py-2 text-right font-mono text-slate-300">
                  <template v-if="entry.kind === 'bpr'">{{ NOT_APPLICABLE }}</template>
                  <template v-else>{{ t('industry.blueprints.efficiency', { me: entry.me, te: entry.te }) }}</template>
                </td>
                <td class="px-4 py-2 text-right font-mono text-slate-300">
                  <template v-if="entry.kind === 'bpr'">{{ NOT_APPLICABLE }}</template>
                  <template v-else-if="entry.runs === null">{{ t('industry.blueprints.unlimitedRuns') }}</template>
                  <template v-else>{{ t('industry.blueprints.runs', { count: entry.runs }) }}</template>
                </td>
                <td class="px-4 py-2 text-slate-300">
                  <span
                    v-if="entry.scope === 'corporation'"
                    class="mr-1 px-2 py-0.5 rounded-sm text-xs border bg-slate-500/10 text-slate-400 border-slate-500/20"
                  >{{ t('industry.blueprints.corporation') }}</span>
                  <span v-if="entry.ownerName !== null">{{ entry.ownerName }}</span>
                  <span v-if="entry.divisionNumber !== null" class="text-slate-500"> · {{ entry.divisionName ?? t('industry.blueprints.division', { number: entry.divisionNumber }) }}</span>
                  <span class="text-slate-500 font-mono text-xs"> · #{{ entry.locationId }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
