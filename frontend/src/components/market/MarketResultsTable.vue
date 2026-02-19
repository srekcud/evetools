<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import { useMarketStore } from '@/stores/market'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'

const { getTypeIconUrl, onImageError } = useEveImages()

interface TableItem {
  typeId: number
  typeName: string
  groupName?: string
  categoryName?: string
  jitaSell: number | null
  jitaBuy: number | null
  spread?: number | null
  avgDailyVolume?: number | null
  change30d: number | null
}

defineProps<{
  items: TableItem[]
  selectedTypeId?: number | null
  showCategory?: boolean
}>()

const emit = defineEmits<{
  select: [typeId: number]
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()
const marketStore = useMarketStore()

function isFavorite(typeId: number): boolean {
  return marketStore.favorites.some(f => f.typeId === typeId)
}

async function toggleFavorite(typeId: number): Promise<void> {
  if (isFavorite(typeId)) {
    await marketStore.removeFavorite(typeId)
  } else {
    await marketStore.addFavorite(typeId)
  }
}

function formatVolume(value: number | null | undefined): string {
  if (value === null || value === undefined) return '---'
  if (value >= 1_000_000_000) return (value / 1_000_000_000).toFixed(1) + 'B'
  if (value >= 1_000_000) return (value / 1_000_000).toFixed(0) + 'M'
  if (value >= 1_000) return (value / 1_000).toFixed(0) + 'K'
  return value.toFixed(0)
}
</script>

<template>
  <div class="bg-slate-900/50 border border-slate-800/50 rounded-xl overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full">
        <thead>
          <tr class="border-b border-slate-800/50">
            <th class="text-left px-4 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">Item</th>
            <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">{{ t('market.detail.jitaSell') }}</th>
            <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider hidden md:table-cell">{{ t('market.detail.jitaBuy') }}</th>
            <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider hidden lg:table-cell">{{ t('market.detail.spread') }}</th>
            <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider hidden lg:table-cell">{{ t('market.detail.volume') }}</th>
            <th class="text-right px-4 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider">{{ t('market.detail.change30d') }}</th>
            <th class="text-center px-4 py-3 text-xs font-medium text-slate-500 uppercase tracking-wider w-10"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/30">
          <tr
            v-for="item in items"
            :key="item.typeId"
            class="hover:bg-cyan-500/5 cursor-pointer transition-colors group"
            @click="emit('select', item.typeId)"
          >
            <td class="px-4 py-3">
              <div class="flex items-center gap-3">
                <img
                  :src="getTypeIconUrl(item.typeId, 32)"
                  @error="onImageError"
                  :alt="item.typeName"
                  class="w-8 h-8 rounded group-hover:scale-105 transition-transform shrink-0"
                  loading="lazy"
                />
                <div>
                  <div class="text-sm font-medium text-slate-200 group-hover:text-cyan-400 transition-colors flex items-center gap-1.5">
                    {{ item.typeName }}
                    <OpenInGameButton type="market" :target-id="item.typeId" />
                  </div>
                  <div v-if="item.categoryName" class="text-xs text-slate-500">{{ item.categoryName }}</div>
                </div>
              </div>
            </td>
            <td class="px-4 py-3 text-right font-mono text-sm text-cyan-400">
              {{ formatIsk(item.jitaSell) }}
            </td>
            <td class="px-4 py-3 text-right font-mono text-sm text-amber-400 hidden md:table-cell">
              {{ formatIsk(item.jitaBuy) }}
            </td>
            <td class="px-4 py-3 text-right font-mono text-sm text-slate-400 hidden lg:table-cell">
              {{ item.spread !== null && item.spread !== undefined ? item.spread.toFixed(1) + '%' : '---' }}
            </td>
            <td class="px-4 py-3 text-right font-mono text-sm text-slate-300 hidden lg:table-cell">
              {{ formatVolume(item.avgDailyVolume) }}
            </td>
            <td class="px-4 py-3 text-right">
              <span
                v-if="item.change30d != null && item.change30d > 0"
                class="inline-flex items-center gap-1 text-emerald-400 text-xs font-mono"
              >
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
                +{{ item.change30d.toFixed(1) }}%
              </span>
              <span
                v-else-if="item.change30d != null && item.change30d < 0"
                class="inline-flex items-center gap-1 text-red-400 text-xs font-mono"
              >
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
                {{ item.change30d.toFixed(1) }}%
              </span>
              <span
                v-else-if="item.change30d != null"
                class="text-slate-500 text-xs font-mono"
              >
                0.0%
              </span>
              <span v-else class="text-slate-600 text-xs font-mono">---</span>
            </td>
            <td class="px-4 py-3 text-center">
              <button
                @click.stop="toggleFavorite(item.typeId)"
                class="transition-colors"
                :class="isFavorite(item.typeId) ? 'text-amber-400 hover:text-amber-300' : 'text-slate-600 hover:text-amber-400'"
                :title="isFavorite(item.typeId) ? t('market.favorite.remove') : t('market.favorite.add')"
              >
                <svg
                  v-if="isFavorite(item.typeId)"
                  class="w-4 h-4"
                  fill="currentColor"
                  viewBox="0 0 20 20"
                >
                  <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                </svg>
                <svg
                  v-else
                  class="w-4 h-4"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 20 20"
                >
                  <path stroke-width="1.5" d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                </svg>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Empty state -->
    <div v-if="items.length === 0" class="p-8 text-center">
      <p class="text-slate-500">{{ t('market.search.noResults') }}</p>
    </div>

    <!-- Footer pagination stub -->
    <div v-if="items.length > 0" class="flex items-center justify-between px-4 py-3 border-t border-slate-800/50">
      <span class="text-xs text-slate-500">{{ t('market.showingResults', { count: items.length }) }}</span>
    </div>
  </div>
</template>
