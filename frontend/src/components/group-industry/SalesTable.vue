<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useFormatters } from '@/composables/useFormatters'
import type { RecordSaleInput } from '@/stores/group-industry/types'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const { t } = useI18n()

const props = defineProps<{
  projectId: string
  isAdmin: boolean
}>()

const store = useGroupDistributionStore()
const { formatNumber, formatDate } = useFormatters()

// Inline sale form state
const showSaleForm = ref(false)
const saleForm = ref<RecordSaleInput>({
  typeId: 0,
  typeName: '',
  quantity: 1,
  unitPrice: 0,
  venue: '',
})
const submittingSale = ref(false)

const totalQuantity = computed(() =>
  store.sales.reduce((sum, s) => sum + s.quantity, 0),
)
const totalRevenue = computed(() =>
  store.sales.reduce((sum, s) => sum + s.totalPrice, 0),
)

function toggleSaleForm(): void {
  showSaleForm.value = !showSaleForm.value
  if (showSaleForm.value) {
    saleForm.value = { typeId: 0, typeName: '', quantity: 1, unitPrice: 0, venue: '' }
  }
}

async function handleRecordSale(): Promise<void> {
  if (!saleForm.value.typeName || saleForm.value.quantity <= 0 || saleForm.value.unitPrice <= 0) return
  submittingSale.value = true
  try {
    await store.recordSale(props.projectId, saleForm.value)
    showSaleForm.value = false
  } finally {
    submittingSale.value = false
  }
}

onMounted(() => {
  store.fetchSales(props.projectId)
})
</script>

<template>
  <div class="mb-8">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold text-slate-100">Sales</h2>
      <button
        v-if="isAdmin"
        class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 transition-all hover:-translate-y-px"
        @click="toggleSaleForm"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Record Sale
      </button>
    </div>

    <!-- Loading -->
    <div v-if="store.loading" class="flex items-center justify-center py-12">
      <LoadingSpinner size="lg" class="text-cyan-400" />
    </div>

    <!-- Empty state -->
    <div
      v-else-if="store.sales.length === 0 && !showSaleForm"
      class="bg-slate-900/80 rounded-xl border border-slate-800 p-12 text-center"
    >
      <svg class="w-10 h-10 mx-auto text-slate-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-sm text-slate-500">No sales recorded yet</p>
      <p class="text-xs text-slate-600 mt-1">Record sales once items have been sold on the market</p>
    </div>

    <!-- Sales Table -->
    <template v-else>
      <div class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-800">
              <th class="text-left py-3 px-6">Date</th>
              <th class="text-right py-3 px-3">Quantity</th>
              <th class="text-right py-3 px-3">Unit Price</th>
              <th class="text-right py-3 px-3">Revenue</th>
              <th class="text-left py-3 px-6">Venue</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800/30">
            <tr
              v-for="sale in store.sales"
              :key="sale.id"
              class="hover:bg-slate-800/30"
            >
              <td class="py-3 px-6 text-slate-300">{{ formatDate(sale.soldAt) }}</td>
              <td class="py-3 px-3 text-right font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                {{ formatNumber(sale.quantity, 0) }}
              </td>
              <td class="py-3 px-3 text-right font-mono text-slate-300" style="font-variant-numeric: tabular-nums;">
                {{ formatNumber(sale.unitPrice, 0) }}
              </td>
              <td class="py-3 px-3 text-right font-mono text-emerald-400" style="font-variant-numeric: tabular-nums;">
                {{ formatNumber(sale.totalPrice, 0) }}
              </td>
              <td class="py-3 px-6">
                <span
                  v-if="sale.venue"
                  class="text-xs px-2 py-0.5 rounded bg-amber-500/10 text-amber-400 border border-amber-500/20"
                >{{ sale.venue }}</span>
                <span v-else class="text-xs text-slate-600">--</span>
              </td>
            </tr>
            <!-- Total row -->
            <tr v-if="store.sales.length > 0" class="bg-slate-800/30 border-t-2 border-slate-700">
              <td class="py-3 px-6 text-slate-200 font-semibold">{{ t('common.status.total') }}</td>
              <td class="py-3 px-3 text-right font-mono font-semibold text-slate-200" style="font-variant-numeric: tabular-nums;">
                {{ formatNumber(totalQuantity, 0) }}
              </td>
              <td class="py-3 px-3"></td>
              <td class="py-3 px-3 text-right font-mono font-bold text-emerald-400" style="font-variant-numeric: tabular-nums;">
                {{ formatNumber(totalRevenue, 0) }}
              </td>
              <td class="py-3 px-6"></td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Inline Sale Form (admin only) -->
      <div
        v-if="showSaleForm && isAdmin"
        class="mt-4 bg-slate-900/80 rounded-xl border border-cyan-500/20 p-5"
      >
        <h3 class="text-sm font-semibold text-slate-200 mb-4">Record a Sale</h3>
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
          <div>
            <label class="text-xs text-slate-500 mb-1 block">Item Name</label>
            <input
              v-model="saleForm.typeName"
              type="text"
              placeholder="e.g. Ishtar"
              class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 placeholder-slate-600 focus:border-cyan-500 focus:outline-none"
            />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">Quantity</label>
            <input
              v-model.number="saleForm.quantity"
              type="number"
              min="1"
              class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 font-mono focus:border-cyan-500 focus:outline-none"
            />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">Unit Price (ISK)</label>
            <input
              v-model.number="saleForm.unitPrice"
              type="number"
              min="0"
              class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 font-mono focus:border-cyan-500 focus:outline-none"
            />
          </div>
          <div>
            <label class="text-xs text-slate-500 mb-1 block">Venue</label>
            <input
              v-model="saleForm.venue"
              type="text"
              placeholder="e.g. Jita 4-4"
              class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 placeholder-slate-600 focus:border-cyan-500 focus:outline-none"
            />
          </div>
          <div class="flex items-end">
            <button
              :disabled="submittingSale || !saleForm.typeName || saleForm.quantity <= 0 || saleForm.unitPrice <= 0"
              class="w-full px-4 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:bg-slate-700 disabled:text-slate-500 rounded-lg text-white text-sm font-medium transition-colors flex items-center justify-center gap-2"
              @click="handleRecordSale"
            >
              <LoadingSpinner v-if="submittingSale" size="sm" />
              <template v-else>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                Save
              </template>
            </button>
          </div>
        </div>
      </div>
    </template>

    <!-- Error -->
    <div v-if="store.error" class="mt-4 bg-red-500/10 border border-red-500/20 rounded-xl p-4">
      <p class="text-sm text-red-400">{{ store.error }}</p>
    </div>
  </div>
</template>
