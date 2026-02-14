<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import { useMiningPricing } from '@/composables/useMiningPricing'
import { useContextMenu } from '@/composables/useContextMenu'
import type { MiningEntry } from '@/stores/ledger'
import MiningEntryMenu from './MiningEntryMenu.vue'

const props = defineProps<{
  entries: MiningEntry[]
  selectedStructureId: number | null
  reprocessYield: number
}>()

const emit = defineEmits<{
  'update-usage': [id: string, usage: MiningEntry['usage']]
}>()

const { t } = useI18n()
const { formatIskShort, formatNumber, formatDate } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

// Wrap reprocessYield as a ref for the composable
const reprocessYieldRef = computed(() => props.reprocessYield)
const entriesRef = computed(() => props.entries)
const { getBestPrice } = useMiningPricing(entriesRef, reprocessYieldRef)

// Context menu
const { menuPosition, activeMenuId: activeMenuEntryId, openMenu, closeMenu } = useContextMenu()

// Multi-select state
const selectedEntryIds = ref<Set<string>>(new Set())
const isMultiSelectMode = computed(() => selectedEntryIds.value.size > 0)

// Pagination
const currentPage = ref(1)
const itemsPerPage = 20

const totalPages = computed(() => Math.ceil(props.entries.length / itemsPerPage))
const paginatedEntries = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage
  return props.entries.slice(start, start + itemsPerPage)
})

function goToPage(page: number) {
  if (page >= 1 && page <= totalPages.value) {
    currentPage.value = page
    clearSelection()
  }
}

function toggleEntrySelection(entryId: string, event?: Event) {
  event?.stopPropagation()
  const newSet = new Set(selectedEntryIds.value)
  if (newSet.has(entryId)) {
    newSet.delete(entryId)
  } else {
    newSet.add(entryId)
  }
  selectedEntryIds.value = newSet
}

function selectAllEntries() {
  const pageIds = paginatedEntries.value.map(e => e.id)
  selectedEntryIds.value = new Set(pageIds)
}

function clearSelection() {
  selectedEntryIds.value = new Set()
  closeMenu()
}

function openEntryMenu(entry: MiningEntry, event: MouseEvent) {
  event.preventDefault()
  event.stopPropagation()

  if (activeMenuEntryId.value === entry.id) {
    clearSelection()
    return
  }

  if (isMultiSelectMode.value && !selectedEntryIds.value.has(entry.id)) {
    toggleEntrySelection(entry.id, event)
    return
  }

  if (!isMultiSelectMode.value) {
    selectedEntryIds.value = new Set([entry.id])
  }

  openMenu(entry.id, event)
}

function handleSetUsage(usage: MiningEntry['usage']) {
  if (activeMenuEntryId.value) {
    emit('update-usage', activeMenuEntryId.value, usage)
    closeMenu()
  }
}

function setSelectedEntriesUsage(usage: MiningEntry['usage']) {
  for (const id of selectedEntryIds.value) {
    emit('update-usage', id, usage)
  }
  clearSelection()
}

function getUsageLabel(usage: MiningEntry['usage']): string {
  switch (usage) {
    case 'sold': return t('ledger.mining.usage.sold')
    case 'corp_project': return t('ledger.mining.usage.corpProject')
    case 'industry': return t('ledger.mining.usage.industry')
    default: return t('ledger.mining.usage.unknown')
  }
}

function getUsageColor(usage: MiningEntry['usage']): string {
  switch (usage) {
    case 'sold': return 'text-emerald-400'
    case 'corp_project': return 'text-amber-400'
    case 'industry': return 'text-blue-400'
    default: return 'text-slate-500'
  }
}

// Reset page when entries change from parent
defineExpose({ resetPage: () => { currentPage.value = 1 } })
</script>

<template>
  <!-- Multi-select action bar -->
  <div
    v-if="isMultiSelectMode"
    class="bg-slate-800 border border-cyan-500/30 rounded-xl p-4 flex items-center justify-between sticky top-0 z-10"
  >
    <div class="flex items-center gap-4">
      <span class="text-cyan-400 font-medium">{{ t('ledger.mining.nSelected', { count: selectedEntryIds.size }) }}</span>
      <button
        @click="selectAllEntries"
        class="text-sm text-slate-400 hover:text-white"
      >
        {{ t('ledger.mining.selectAll') }}
      </button>
      <button
        @click="clearSelection"
        class="text-sm text-slate-400 hover:text-white"
      >
        {{ t('common.actions.cancel') }}
      </button>
    </div>
    <div class="flex items-center gap-2">
      <span class="text-sm text-slate-400 mr-2">{{ t('ledger.mining.setAs') }} :</span>
      <button
        @click="setSelectedEntriesUsage('sold')"
        class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 rounded-lg text-white text-sm font-medium"
      >
        {{ t('ledger.mining.personal') }}
      </button>
      <button
        @click="setSelectedEntriesUsage('corp_project')"
        class="px-3 py-1.5 bg-amber-600 hover:bg-amber-500 rounded-lg text-white text-sm font-medium"
      >
        {{ t('ledger.mining.corp') }}
      </button>
    </div>
  </div>

  <!-- Mining Entries Table -->
  <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden relative">
    <div class="px-6 py-4 border-b border-slate-800">
      <div class="flex items-center justify-between mb-2">
        <div class="flex items-center gap-3">
          <h3 class="text-lg font-semibold text-white">{{ t('ledger.mining.entries') }}</h3>
          <span class="text-xs text-slate-500">({{ t('ledger.mining.entriesCount', { count: entries.length, current: currentPage, total: totalPages }) }})</span>
        </div>
        <p class="text-xs text-slate-500">{{ t('ledger.mining.clickToChangeStatus') }}</p>
      </div>
      <!-- Legend -->
      <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
        <span class="text-slate-500">{{ t('ledger.mining.bestPrice') }} :</span>
        <div class="flex items-center gap-1">
          <span class="text-cyan-400">&#x25CF;</span>
          <span class="text-slate-500">Jita comp.</span>
        </div>
        <div class="flex items-center gap-1">
          <span class="text-purple-400">&#x25CF;</span>
          <span class="text-slate-500">Jita reproc.</span>
        </div>
        <div class="flex items-center gap-1">
          <span class="text-teal-400">&#x25CF;</span>
          <span class="text-slate-500">Struct. comp.</span>
        </div>
        <div class="flex items-center gap-1">
          <span class="text-lime-400">&#x25CF;</span>
          <span class="text-slate-500">Struct. reproc.</span>
        </div>
      </div>
    </div>
    <!-- Table -->
    <div class="overflow-x-auto">
      <table class="w-full">
        <thead class="bg-slate-900/50">
          <tr>
            <th class="px-4 py-3 w-10">
              <input
                type="checkbox"
                :checked="selectedEntryIds.size === paginatedEntries.length && paginatedEntries.length > 0"
                :indeterminate="selectedEntryIds.size > 0 && selectedEntryIds.size < paginatedEntries.length"
                @change="selectedEntryIds.size === paginatedEntries.length ? clearSelection() : selectAllEntries()"
                class="w-4 h-4 rounded-sm border-slate-600 bg-slate-700 text-cyan-500 focus:ring-cyan-500 focus:ring-offset-slate-800"
              />
            </th>
            <th class="px-3 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">{{ t('ledger.mining.date') }}</th>
            <th class="px-3 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">{{ t('ledger.mining.type') }}</th>
            <th class="px-3 py-3 text-right text-xs font-medium text-slate-400 uppercase tracking-wider">{{ t('ledger.mining.qty') }}</th>
            <th class="px-3 py-3 text-right text-xs font-medium text-slate-400 uppercase tracking-wider" title="Prix equivalent compresse Jita (divise par 100)">Compress</th>
            <th class="px-3 py-3 text-right text-xs font-medium text-slate-400 uppercase tracking-wider" :title="`Valeur reprocess par unite (${reprocessYield.toFixed(2)}% yield)`">Reproc. {{ reprocessYield.toFixed(2) }}%</th>
            <th v-if="selectedStructureId" class="px-3 py-3 text-right text-xs font-medium text-slate-400 uppercase tracking-wider" title="Prix structure selectionnee">Structure</th>
            <th class="px-3 py-3 text-right text-xs font-medium text-slate-400 uppercase tracking-wider">{{ t('common.status.total') }}</th>
            <th class="px-3 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">{{ t('ledger.mining.status') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800">
          <tr
            v-for="entry in paginatedEntries"
            :key="entry.id"
            class="entry-row hover:bg-slate-900/30 cursor-pointer transition-colors"
            :class="{
              'bg-slate-900/50': activeMenuEntryId === entry.id,
              'bg-cyan-900/20': selectedEntryIds.has(entry.id)
            }"
            @click="openEntryMenu(entry, $event)"
          >
            <td class="px-4 py-3 w-10" @click.stop>
              <input
                type="checkbox"
                :checked="selectedEntryIds.has(entry.id)"
                @change="toggleEntrySelection(entry.id, $event)"
                class="w-4 h-4 rounded-sm border-slate-600 bg-slate-700 text-cyan-500 focus:ring-cyan-500 focus:ring-offset-slate-800"
              />
            </td>
            <td class="px-3 py-3 text-sm text-slate-300">{{ formatDate(entry.date) }}</td>
            <td class="px-3 py-3">
              <div class="flex items-center gap-2">
                <img
                  :src="getTypeIconUrl(entry.typeId)"
                  :alt="entry.typeName"
                  class="w-5 h-5"
                  @error="onImageError"
                />
                <span class="text-sm text-white truncate max-w-[120px]" :title="entry.typeName">{{ entry.typeName }}</span>
              </div>
            </td>
            <td class="px-3 py-3 text-sm text-right text-slate-300">{{ formatNumber(entry.quantity, 0) }}</td>
            <td class="px-3 py-3 text-sm text-right">
              <span v-if="entry.compressedEquivalentPrice" class="text-cyan-400" :title="`${entry.compressedTypeName}: ${formatNumber(entry.compressedUnitPrice || 0, 0)} ISK/u`">
                {{ formatNumber(entry.compressedEquivalentPrice, 1) }}
              </span>
              <span v-else class="text-slate-600">-</span>
            </td>
            <td class="px-3 py-3 text-sm text-right">
              <span v-if="entry.reprocessValue" class="text-purple-400" :title="`Valeur reprocess par unite (${reprocessYield.toFixed(2)}% yield)`">
                {{ formatNumber(entry.reprocessValue, 2) }}
              </span>
              <span v-else class="text-slate-600">-</span>
            </td>
            <td v-if="selectedStructureId" class="px-3 py-3 text-sm text-right">
              <span v-if="entry.structureCompressedUnitPrice" class="text-emerald-400" :title="`Compresse: ${formatNumber(entry.structureCompressedUnitPrice, 0)} ISK/u`">
                {{ formatNumber(entry.structureCompressedUnitPrice / 100, 1) }}
              </span>
              <span v-else-if="entry.structureUnitPrice" class="text-slate-400">
                {{ formatNumber(entry.structureUnitPrice, 0) }}
              </span>
              <span v-else class="text-slate-600">-</span>
            </td>
            <td class="px-3 py-3 text-sm text-right">
              <span
                v-if="getBestPrice(entry).value > 0"
                :class="getBestPrice(entry).color"
                class="font-medium"
                :title="getBestPrice(entry).source"
              >
                {{ formatIskShort(getBestPrice(entry).value * entry.quantity) }}
              </span>
              <span v-else class="text-slate-600">-</span>
            </td>
            <td class="px-3 py-3 text-sm">
              <span :class="getUsageColor(entry.usage)" class="text-xs font-medium">
                {{ getUsageLabel(entry.usage) }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="entries.length === 0" class="p-8 text-center text-slate-500">
      {{ t('ledger.mining.noData') }}
    </div>

    <!-- Pagination -->
    <div v-if="totalPages > 1" class="px-6 py-4 border-t border-slate-800 flex items-center justify-between">
      <div class="text-sm text-slate-400">
        {{ (currentPage - 1) * itemsPerPage + 1 }}-{{ Math.min(currentPage * itemsPerPage, entries.length) }} sur {{ entries.length }}
      </div>
      <div class="flex items-center gap-1">
        <button
          @click="goToPage(1)"
          :disabled="currentPage === 1"
          class="px-2 py-1 text-sm rounded-sm hover:bg-slate-700/50 disabled:opacity-30 disabled:cursor-not-allowed text-slate-400"
        >
          &lt;&lt;
        </button>
        <button
          @click="goToPage(currentPage - 1)"
          :disabled="currentPage === 1"
          class="px-2 py-1 text-sm rounded-sm hover:bg-slate-700/50 disabled:opacity-30 disabled:cursor-not-allowed text-slate-400"
        >
          &lt;
        </button>
        <template v-for="page in totalPages" :key="page">
          <button
            v-if="page === 1 || page === totalPages || (page >= currentPage - 1 && page <= currentPage + 1)"
            @click="goToPage(page)"
            :class="[
              'px-3 py-1 text-sm rounded-sm transition-colors',
              page === currentPage
                ? 'bg-cyan-600 text-white'
                : 'text-slate-400 hover:bg-slate-700/50'
            ]"
          >
            {{ page }}
          </button>
          <span
            v-else-if="page === currentPage - 2 || page === currentPage + 2"
            class="px-1 text-slate-500"
          >...</span>
        </template>
        <button
          @click="goToPage(currentPage + 1)"
          :disabled="currentPage === totalPages"
          class="px-2 py-1 text-sm rounded-sm hover:bg-slate-700/50 disabled:opacity-30 disabled:cursor-not-allowed text-slate-400"
        >
          &gt;
        </button>
        <button
          @click="goToPage(totalPages)"
          :disabled="currentPage === totalPages"
          class="px-2 py-1 text-sm rounded-sm hover:bg-slate-700/50 disabled:opacity-30 disabled:cursor-not-allowed text-slate-400"
        >
          &gt;&gt;
        </button>
      </div>
    </div>

    <!-- Contextual Menu -->
    <MiningEntryMenu
      v-if="activeMenuEntryId && !isMultiSelectMode"
      :entry-id="activeMenuEntryId"
      :position="menuPosition"
      @set-usage="handleSetUsage"
      @close="closeMenu"
    />
  </div>
</template>
