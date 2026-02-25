<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useEveImages } from '@/composables/useEveImages'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'
import type { EnrichedShoppingItem } from '../ShoppingTab.vue'

defineProps<{
  items: EnrichedShoppingItem[]
}>()

const emit = defineEmits<{
  updateStock: [typeName: string, quantity: number]
}>()

const { t } = useI18n()
const { formatIsk } = useFormatters()
const { getTypeIconUrl, onImageError } = useEveImages()

const editingStockTypeId = ref<number | null>(null)
const editingStockValue = ref('')

function startEditStock(item: EnrichedShoppingItem) {
  editingStockTypeId.value = item.typeId
  editingStockValue.value = item.inStock > 0 ? String(item.inStock) : ''
}

function saveEditStock(typeName: string) {
  if (editingStockTypeId.value === null) return
  editingStockTypeId.value = null
  const qty = parseInt(editingStockValue.value, 10)
  const newQty = isNaN(qty) || qty < 0 ? 0 : qty
  emit('updateStock', typeName, newQty)
}

function cancelEditStock() {
  editingStockTypeId.value = null
}
</script>

<template>
  <div v-if="items.length > 0" class="bg-slate-900 rounded-xl border border-slate-800">
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
          <th class="text-left py-3 px-3">{{ t('industry.shoppingTab.material') }}</th>
          <th class="text-right py-3 px-2">{{ t('industry.shoppingTab.quantity') }}</th>
          <th class="text-center py-3 px-2">{{ t('industry.shoppingTab.stock') }}</th>
          <th class="text-right py-3 px-2">{{ t('industry.shoppingTab.toBuy') }}</th>
          <th class="text-right py-3 px-2">{{ t('industry.shoppingTab.volume') }}</th>
          <th class="text-right py-3 px-2">{{ t('industry.shoppingTab.jitaImport') }}</th>
          <th class="text-right py-3 px-2">{{ t('industry.shoppingTab.structure') }}</th>
          <th class="text-right py-3 px-2">{{ t('industry.shoppingTab.best') }}</th>
          <th class="text-right py-3 px-2">{{ t('industry.shoppingTab.savings') }}</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-800">
        <tr
          v-for="item in items"
          :key="item.typeId"
          class="hover:bg-slate-800/50"
        >
          <td class="py-3 px-3">
            <div class="flex items-center gap-2">
              <img
                :src="getTypeIconUrl(item.typeId, 32)"
                class="w-5 h-5 rounded-sm"
                @error="onImageError"
              />
              <span class="text-slate-200">{{ item.typeName }}</span>
              <OpenInGameButton type="market" :targetId="item.typeId" />
            </div>
          </td>
          <td class="py-3 px-2 text-right font-mono text-slate-300">
            {{ item.quantity.toLocaleString() }}
            <span v-if="item.extraQuantity > 0" class="text-amber-400 text-xs" :title="`+${item.extraQuantity.toLocaleString()} ${t('industry.shoppingTab.suboptimalExtra')}`">
              (+{{ item.extraQuantity.toLocaleString() }})
            </span>
          </td>
          <!-- Stock (editable) -->
          <td class="py-3 px-2 text-center">
            <div class="flex items-center justify-center gap-1.5">
              <span
                class="w-2 h-2 rounded-full shrink-0"
                :class="[
                  item.status === 'ok' ? 'bg-emerald-500' :
                  item.status === 'partial' ? 'bg-amber-500' : 'bg-red-500'
                ]"
              ></span>
              <input
                v-if="editingStockTypeId === item.typeId"
                v-model="editingStockValue"
                type="number"
                min="0"
                class="w-16 bg-slate-700 border border-cyan-500 rounded-sm px-1.5 py-0.5 text-xs text-right font-mono focus:outline-hidden"
                @keydown.enter="saveEditStock(item.typeName)"
                @keydown.escape="cancelEditStock"
                @blur="saveEditStock(item.typeName)"
                autofocus
              />
              <span
                v-else
                @click="startEditStock(item)"
                class="font-mono text-xs cursor-pointer hover:text-cyan-400 min-w-8"
                :class="item.inStock > 0 ? 'text-slate-300' : 'text-slate-600'"
                :title="t('industry.projectDetail.clickToEdit')"
              >
                {{ item.inStock > 0 ? item.inStock.toLocaleString() : '-' }}
              </span>
            </div>
          </td>
          <!-- Missing -->
          <td class="py-3 px-2 text-right font-mono" :class="item.missing > 0 ? 'text-slate-200' : 'text-emerald-400'">
            {{ item.missing > 0 ? item.missing.toLocaleString() : '0' }}
          </td>
          <!-- Volume -->
          <td class="py-3 px-2 text-right font-mono text-slate-400">{{ item.missingVolume.toLocaleString() }} m3</td>
          <!-- Jita -->
          <td class="py-3 px-2 text-right font-mono">
            <div v-if="item.missingJitaWeighted !== null || item.missingJita !== null">
              <div class="flex items-center justify-end gap-1">
                <span
                  v-if="item.missingJitaCoverage !== null && item.missingJitaCoverage < 1.0"
                  class="shrink-0"
                  :class="item.missingJitaCoverage >= 0.5 ? 'text-amber-400' : 'text-red-400'"
                  :title="t('industry.shoppingTab.depthWarning', { available: Math.round((item.missingJitaCoverage ?? 0) * item.quantity).toLocaleString(), total: item.quantity.toLocaleString() })"
                >
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                  </svg>
                </span>
                <span :class="item.bestLocation === 'jita' && item.missing > 0 ? 'text-emerald-400' : 'text-slate-300'">
                  {{ formatIsk(item.missingJitaWeighted ?? item.missingJita) }}
                </span>
              </div>
              <div v-if="item.missingJitaWeighted !== null && item.missingJita !== null" class="text-[10px] text-slate-600 mt-0.5">
                {{ formatIsk(item.missingJita) }}
              </div>
            </div>
            <span v-else class="text-slate-300">-</span>
          </td>
          <!-- Structure -->
          <td class="py-3 px-2 text-right font-mono" :class="item.bestLocation === 'structure' && item.missing > 0 ? 'text-emerald-400' : 'text-slate-300'">
            {{ item.missingStructure !== null ? formatIsk(item.missingStructure) : '-' }}
          </td>
          <!-- Best -->
          <td class="py-3 px-2 text-right font-mono text-emerald-400">
            {{ item.missingBest !== null ? formatIsk(item.missingBest) : '-' }}
          </td>
          <!-- Savings -->
          <td class="py-3 px-2 text-right font-mono text-emerald-400">
            {{ item.missingSavings !== null ? formatIsk(item.missingSavings) : '-' }}
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
