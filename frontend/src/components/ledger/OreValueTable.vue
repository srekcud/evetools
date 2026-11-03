<script setup lang="ts">
import { computed, toRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import {
  useOreValueTable,
  type OreKind,
  type OreValueOption,
  type OreValueRow,
  type OreValueSortColumn,
} from '@/composables/useOreValueTable'
import UnknownValue from '@/components/common/UnknownValue.vue'

const props = defineProps<{
  rows: OreValueRow[]
}>()

const { t } = useI18n()
const { formatNumber } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

const { sortColumn, sortDirection, kindFilter, visibleRows } = useOreValueTable(toRef(props, 'rows'))

const kinds: OreKind[] = ['ore', 'ice', 'gas']

const optionColumns: { option: OreValueOption; field: 'rawIskPerM3' | 'compressedIskPerM3' | 'reprocessedIskPerM3' }[] = [
  { option: 'raw', field: 'rawIskPerM3' },
  { option: 'compressed', field: 'compressedIskPerM3' },
  { option: 'reprocessed', field: 'reprocessedIskPerM3' },
]

const sortableColumns = computed((): { column: OreValueSortColumn; label: string; align: string }[] => [
  { column: 'volume', label: t('ledger.mining.oreValue.volume'), align: 'text-right' },
  { column: 'rawIskPerM3', label: t('ledger.mining.oreValue.raw'), align: 'text-right' },
  { column: 'compressedIskPerM3', label: t('ledger.mining.oreValue.compressed'), align: 'text-right' },
  { column: 'reprocessedIskPerM3', label: t('ledger.mining.oreValue.reprocessed'), align: 'text-right' },
  { column: 'bestIskPerM3', label: t('ledger.mining.oreValue.best'), align: 'text-right' },
  { column: 'gapToBestPercent', label: t('ledger.mining.oreValue.gap'), align: 'text-right' },
])

function sortBy(column: OreValueSortColumn) {
  if (sortColumn.value === column) {
    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
    return
  }
  sortColumn.value = column
  sortDirection.value = column === 'typeName' ? 'asc' : 'desc'
}

function ariaSortOf(column: OreValueSortColumn): 'ascending' | 'descending' | 'none' {
  if (sortColumn.value !== column) return 'none'
  return sortDirection.value === 'asc' ? 'ascending' : 'descending'
}

function sortIndicatorOf(column: OreValueSortColumn): string {
  if (sortColumn.value !== column) return ''
  return sortDirection.value === 'asc' ? '▲' : '▼'
}

function mineralLabel(mineral: OreValueRow['minerals'][number]): string {
  const quantity = mineral.quantityPerUnit === null ? null : formatNumber(mineral.quantityPerUnit)
  if (quantity === null) return mineral.typeName ?? `#${mineral.typeId}`
  return mineral.typeName === null
    ? t('ledger.mining.oreValue.unknownMineral', { typeId: mineral.typeId, quantity })
    : t('ledger.mining.oreValue.mineral', { name: mineral.typeName, quantity })
}

function mineralsLabel(row: OreValueRow): string {
  return row.minerals.map(mineralLabel).join(' · ')
}
</script>

<template>
  <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
    <div class="px-4 py-3 border-b border-slate-800 flex flex-wrap items-center gap-2" role="group" :aria-label="t('ledger.mining.oreValue.familyLabel')">
      <span class="text-xs uppercase tracking-wider text-slate-500">{{ t('ledger.mining.oreValue.familyLabel') }}</span>
      <button
        type="button"
        :aria-pressed="kindFilter === null"
        :class="['px-3 py-1 rounded-sm border text-xs font-medium uppercase tracking-wider transition-colors', kindFilter === null ? 'border-cyan-500/60 bg-cyan-500/10 text-cyan-300' : 'border-slate-700 text-slate-400 hover:text-slate-200']"
        @click="kindFilter = null"
      >
        {{ t('ledger.mining.oreValue.allFamilies') }}
      </button>
      <button
        v-for="kind in kinds"
        :key="kind"
        type="button"
        :aria-pressed="kindFilter === kind"
        :class="['px-3 py-1 rounded-sm border text-xs font-medium uppercase tracking-wider transition-colors', kindFilter === kind ? 'border-cyan-500/60 bg-cyan-500/10 text-cyan-300' : 'border-slate-700 text-slate-400 hover:text-slate-200']"
        @click="kindFilter = kind"
      >
        {{ t(`ledger.mining.oreValue.${kind}`) }}
      </button>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full">
        <thead class="bg-slate-900/50">
          <tr>
            <th scope="col" :aria-sort="ariaSortOf('typeName')" class="px-3 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">
              <button type="button" class="uppercase tracking-wider hover:text-slate-200" :aria-label="t('ledger.mining.oreValue.sortBy', { column: t('ledger.mining.ore') })" @click="sortBy('typeName')">
                {{ t('ledger.mining.ore') }} <span aria-hidden="true">{{ sortIndicatorOf('typeName') }}</span>
              </button>
            </th>
            <th
              v-for="header in sortableColumns"
              :key="header.column"
              scope="col"
              :aria-sort="ariaSortOf(header.column)"
              :class="['px-3 py-3 text-xs font-medium text-slate-400 uppercase tracking-wider whitespace-nowrap', header.align]"
            >
              <button type="button" class="uppercase tracking-wider hover:text-slate-200" :aria-label="t('ledger.mining.oreValue.sortBy', { column: header.label })" @click="sortBy(header.column)">
                {{ header.label }} <span aria-hidden="true">{{ sortIndicatorOf(header.column) }}</span>
              </button>
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800">
          <tr v-for="row in visibleRows" :key="row.typeId" :data-type-id="row.typeId" class="hover:bg-slate-900/30 transition-colors">
            <td class="px-3 py-2 min-w-0 max-w-[16rem]">
              <div class="flex items-center gap-2 min-w-0">
                <img :src="getTypeIconUrl(row.typeId)" alt="" class="w-5 h-5 shrink-0" @error="onImageError" />
                <div class="min-w-0">
                  <div class="text-sm text-white truncate" :title="row.typeName">{{ row.typeName }}</div>
                  <div v-if="row.minerals.length > 0" class="text-xs text-slate-500 truncate" :title="mineralsLabel(row)">{{ mineralsLabel(row) }}</div>
                </div>
              </div>
            </td>
            <td class="px-3 py-2 text-right text-sm text-slate-300 whitespace-nowrap">
              <UnknownValue v-if="row.volume === null" />
              <template v-else>{{ formatNumber(row.volume) }}</template>
            </td>
            <td
              v-for="column in optionColumns"
              :key="column.option"
              :data-column="column.option"
              :class="['px-3 py-2 text-right text-sm whitespace-nowrap', row.bestOption === column.option ? 'bg-cyan-500/10 text-cyan-300 font-semibold ring-1 ring-inset ring-cyan-500/40' : 'text-slate-300']"
            >
              <span v-if="row.bestOption === column.option" data-best-option class="mr-2 text-[0.625rem] uppercase tracking-widest text-cyan-400">
                <span aria-hidden="true">▲ </span>{{ t('ledger.mining.oreValue.best') }}
              </span>
              <span v-if="column.option === 'reprocessed' && !row.reprocessable" :aria-label="t('ledger.mining.oreValue.notReprocessable')">—</span>
              <UnknownValue v-else-if="row[column.field] === null" />
              <template v-else>{{ formatNumber(row[column.field]) }}</template>
            </td>
            <td class="px-3 py-2 text-right text-sm text-slate-300 whitespace-nowrap">
              <UnknownValue v-if="row.bestIskPerM3 === null" />
              <template v-else>{{ formatNumber(row.bestIskPerM3) }}</template>
            </td>
            <td class="px-3 py-2 text-right text-sm text-slate-400 whitespace-nowrap">
              <UnknownValue v-if="row.gapToBestPercent === null" />
              <template v-else>{{ formatNumber(row.gapToBestPercent, 1) }} %</template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
