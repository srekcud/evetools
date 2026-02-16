<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'
import type { ProfitItem, SortField, SortOrder, FilterType } from '@/stores/profitTracker'

const props = defineProps<{
  items: ProfitItem[]
  sortBy: SortField
  sortOrder: SortOrder
  filter: FilterType
}>()

const searchQuery = ref('')

const displayedItems = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  if (!query) return props.items
  return props.items.filter(item => item.typeName.toLowerCase().includes(query))
})

const emit = defineEmits<{
  sort: [field: SortField]
  filter: [filter: FilterType]
  selectItem: [typeId: number]
}>()

const { t } = useI18n()
const { formatIsk, formatNumber, formatDate } = useFormatters()

function profitColor(value: number): string {
  return value >= 0 ? 'text-emerald-400' : 'text-red-400'
}

function marginBadgeClass(margin: number): string {
  if (margin >= 20) return 'bg-emerald-500/15 text-emerald-400'
  if (margin >= 10) return 'bg-amber-500/15 text-amber-400'
  if (margin >= 0) return 'bg-amber-500/15 text-amber-400'
  return 'bg-red-500/15 text-red-400'
}

function sortIcon(field: SortField): string {
  if (props.sortBy !== field) return ''
  return props.sortOrder === 'asc' ? 'rotate-180' : ''
}

function isSortActive(field: SortField): boolean {
  return props.sortBy === field
}

// Totals (computed on displayed/filtered items)
const totals = computed(() => {
  const list = displayedItems.value
  return {
    quantitySold: list.reduce((sum, i) => sum + i.quantitySold, 0),
    materialCost: list.reduce((sum, i) => sum + i.materialCost, 0),
    jobInstallCost: list.reduce((sum, i) => sum + i.jobInstallCost, 0),
    totalCost: list.reduce((sum, i) => sum + i.totalCost, 0),
    revenue: list.reduce((sum, i) => sum + i.revenue, 0),
    profit: list.reduce((sum, i) => sum + i.profit, 0),
  }
})

const totalMargin = computed(() => {
  if (totals.value.revenue === 0) return 0
  return (totals.value.profit / totals.value.revenue) * 100
})

interface ColumnDef {
  key: SortField
  label: string
  align: 'left' | 'right'
}

const columns = computed<ColumnDef[]>(() => [
  { key: 'typeName', label: t('profitTracker.table.item'), align: 'left' },
  { key: 'quantitySold', label: t('profitTracker.table.qtySold'), align: 'right' },
  { key: 'materialCost', label: t('profitTracker.table.materialCost'), align: 'right' },
  { key: 'jobInstallCost', label: t('profitTracker.table.jobCost'), align: 'right' },
  { key: 'totalCost', label: t('profitTracker.table.totalCost'), align: 'right' },
  { key: 'revenue', label: t('profitTracker.table.revenue'), align: 'right' },
  { key: 'profit', label: t('profitTracker.table.profit'), align: 'right' },
  { key: 'marginPercent', label: t('profitTracker.table.margin'), align: 'right' },
  { key: 'lastSaleDate', label: t('profitTracker.table.lastSale'), align: 'right' },
])

const filterOptions: FilterType[] = ['all', 'profit', 'loss']
</script>

<template>
  <div class="space-y-4">
    <!-- Search + Filters -->
    <div class="bg-slate-900 rounded-xl border border-slate-800 p-4 flex items-center gap-4 flex-wrap">
      <!-- Search -->
      <div class="relative flex-1 min-w-[200px] max-w-xs">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>
        <input
          v-model="searchQuery"
          type="text"
          :placeholder="t('profitTracker.table.search')"
          class="w-full bg-slate-800 border border-slate-700 rounded-lg pl-9 pr-3 py-2 text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-cyan-500 transition-colors"
        />
      </div>

      <!-- Filter pills -->
      <div class="flex items-center gap-1 bg-slate-800 rounded-lg p-0.5">
        <button
          v-for="f in filterOptions"
          :key="f"
          @click="emit('filter', f)"
          :class="[
            'px-3 py-1.5 rounded-md text-xs font-medium transition-colors',
            filter === f
              ? 'bg-cyan-600 text-white'
              : 'text-slate-400 hover:text-white'
          ]"
        >
          {{ t('profitTracker.filter.' + f) }}
        </button>
      </div>
    </div>

    <!-- Table -->
    <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-800">
              <th
                v-for="col in columns"
                :key="col.key"
                @click="emit('sort', col.key)"
                :class="[
                  'px-4 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider cursor-pointer select-none hover:text-cyan-300 transition-colors',
                  col.align === 'left' ? 'text-left' : 'text-right',
                ]"
              >
                <span class="flex items-center gap-1" :class="col.align === 'right' ? 'justify-end' : ''">
                  {{ col.label }}
                  <svg
                    v-if="isSortActive(col.key)"
                    class="w-3 h-3 text-cyan-400 transition-transform"
                    :class="sortIcon(col.key)"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 13l-5 5m0 0l-5-5m5 5V6"/>
                  </svg>
                  <svg
                    v-else
                    class="w-3 h-3 opacity-0 group-hover:opacity-30"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12"/>
                  </svg>
                </span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(item, index) in displayedItems"
              :key="item.productTypeId"
              @click="emit('selectItem', item.productTypeId)"
              class="border-b border-slate-800/50 cursor-pointer transition-colors hover:bg-cyan-500/5"
              :class="index % 2 === 0 ? 'bg-slate-900' : 'bg-slate-800/30'"
            >
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <img
                    :src="`https://images.evetech.net/types/${item.productTypeId}/icon?size=32`"
                    :alt="item.typeName"
                    class="w-8 h-8 rounded border border-slate-700"
                    loading="lazy"
                  />
                  <div class="flex items-center gap-2">
                    <span class="font-medium text-slate-200">{{ item.typeName }}</span>
                    <OpenInGameButton type="market" :target-id="item.productTypeId" />
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 text-right font-mono text-slate-300">{{ formatNumber(item.quantitySold, 0) }}</td>
              <td class="px-4 py-3 text-right font-mono text-slate-400">{{ formatIsk(item.materialCost) }}</td>
              <td class="px-4 py-3 text-right font-mono text-slate-400">{{ formatIsk(item.jobInstallCost) }}</td>
              <td class="px-4 py-3 text-right font-mono text-slate-300">{{ formatIsk(item.totalCost) }}</td>
              <td class="px-4 py-3 text-right font-mono text-slate-200">{{ formatIsk(item.revenue) }}</td>
              <td class="px-4 py-3 text-right font-mono font-medium" :class="profitColor(item.profit)">
                {{ item.profit >= 0 ? '+' : '' }}{{ formatIsk(item.profit) }}
              </td>
              <td class="px-4 py-3 text-right">
                <span
                  class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                  :class="marginBadgeClass(item.marginPercent)"
                >
                  {{ item.marginPercent.toFixed(1) }}%
                </span>
              </td>
              <td class="px-4 py-3 text-right text-xs text-slate-500">
                {{ item.lastSaleDate ? formatDate(item.lastSaleDate) : '---' }}
              </td>
            </tr>
          </tbody>

          <!-- Footer totals -->
          <tfoot v-if="displayedItems.length > 0">
            <tr class="border-t-2 border-slate-700 bg-slate-800/50">
              <td class="px-4 py-3 font-medium text-slate-300">
                {{ t('common.status.total') }} ({{ displayedItems.length }} {{ displayedItems.length === 1 ? 'item' : 'items' }})
              </td>
              <td class="px-4 py-3 text-right font-mono font-medium text-slate-300">{{ formatNumber(totals.quantitySold, 0) }}</td>
              <td class="px-4 py-3 text-right font-mono font-medium text-slate-300">{{ formatIsk(totals.materialCost) }}</td>
              <td class="px-4 py-3 text-right font-mono font-medium text-slate-300">{{ formatIsk(totals.jobInstallCost) }}</td>
              <td class="px-4 py-3 text-right font-mono font-medium text-slate-300">{{ formatIsk(totals.totalCost) }}</td>
              <td class="px-4 py-3 text-right font-mono font-medium text-slate-200">{{ formatIsk(totals.revenue) }}</td>
              <td class="px-4 py-3 text-right font-mono font-bold" :class="profitColor(totals.profit)">
                {{ totals.profit >= 0 ? '+' : '' }}{{ formatIsk(totals.profit) }}
              </td>
              <td class="px-4 py-3 text-right">
                <span
                  class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold"
                  :class="marginBadgeClass(totalMargin)"
                >
                  {{ totalMargin.toFixed(1) }}%
                </span>
              </td>
              <td class="px-4 py-3"></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Empty state -->
      <div v-if="displayedItems.length === 0" class="px-6 py-12 text-center">
        <svg class="w-12 h-12 text-slate-700 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
        </svg>
        <p class="text-sm text-slate-500">{{ t('common.status.empty') }}</p>
      </div>
    </div>
  </div>
</template>
