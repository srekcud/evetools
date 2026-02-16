<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'

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
const { formatIsk, formatNumber } = useFormatters()
</script>

<template>
  <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-800">
            <th class="text-left px-4 py-3 text-slate-400 font-medium">Item</th>
            <th v-if="showCategory" class="text-left px-4 py-3 text-slate-400 font-medium hidden md:table-cell">{{ t('market.detail.category') }}</th>
            <th class="text-right px-4 py-3 text-slate-400 font-medium">{{ t('market.detail.jitaSell') }}</th>
            <th class="text-right px-4 py-3 text-slate-400 font-medium hidden md:table-cell">{{ t('market.detail.jitaBuy') }}</th>
            <th class="text-right px-4 py-3 text-slate-400 font-medium hidden lg:table-cell">{{ t('market.detail.spread') }}</th>
            <th class="text-right px-4 py-3 text-slate-400 font-medium hidden lg:table-cell">{{ t('market.detail.volume') }}</th>
            <th class="text-right px-4 py-3 text-slate-400 font-medium">{{ t('market.detail.change30d') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/50">
          <tr
            v-for="item in items"
            :key="item.typeId"
            class="hover:bg-slate-800/50 cursor-pointer transition-colors"
            :class="{ 'bg-cyan-500/5 border-l-2 border-l-cyan-500': selectedTypeId === item.typeId }"
            @click="emit('select', item.typeId)"
          >
            <td class="px-4 py-3">
              <div class="flex items-center gap-2">
                <img
                  :src="`https://images.evetech.net/types/${item.typeId}/icon?size=32`"
                  :alt="item.typeName"
                  class="w-6 h-6 rounded shrink-0"
                  loading="lazy"
                />
                <span class="text-slate-100 font-medium truncate">{{ item.typeName }}</span>
                <OpenInGameButton type="market" :target-id="item.typeId" />
              </div>
            </td>
            <td v-if="showCategory" class="px-4 py-3 text-slate-400 hidden md:table-cell">
              {{ item.categoryName }}
            </td>
            <td class="px-4 py-3 text-right font-mono text-cyan-400">
              {{ formatIsk(item.jitaSell) }}
            </td>
            <td class="px-4 py-3 text-right font-mono text-emerald-400 hidden md:table-cell">
              {{ formatIsk(item.jitaBuy) }}
            </td>
            <td class="px-4 py-3 text-right font-mono text-slate-400 hidden lg:table-cell">
              {{ item.spread !== null && item.spread !== undefined ? item.spread.toFixed(1) + '%' : '---' }}
            </td>
            <td class="px-4 py-3 text-right font-mono text-slate-400 hidden lg:table-cell">
              {{ formatNumber(item.avgDailyVolume, 0) }}
            </td>
            <td class="px-4 py-3 text-right font-mono">
              <span
                v-if="item.change30d !== null"
                :class="item.change30d >= 0 ? 'text-emerald-400' : 'text-red-400'"
              >
                {{ item.change30d >= 0 ? '+' : '' }}{{ item.change30d.toFixed(1) }}%
              </span>
              <span v-else class="text-slate-600">---</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Empty state -->
    <div v-if="items.length === 0" class="p-8 text-center">
      <p class="text-slate-500">{{ t('market.search.noResults') }}</p>
    </div>
  </div>
</template>
