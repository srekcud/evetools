<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMarketStore, type MarketTypeDetail as TypeDetailType } from '@/stores/market'
import { useFormatters } from '@/composables/useFormatters'
import PriceChart from './PriceChart.vue'
import VolumeChart from './VolumeChart.vue'
import OrderBookPanel from './OrderBookPanel.vue'
import PriceComparisonTable from './PriceComparisonTable.vue'
import AlertFormModal from './AlertFormModal.vue'
import OpenInGameButton from '@/components/common/OpenInGameButton.vue'

const props = defineProps<{
  detail: TypeDetailType
}>()

const { t } = useI18n()
const marketStore = useMarketStore()
const { formatIsk, formatNumber } = useFormatters()

const selectedPeriod = ref(30)
const showAlertModal = ref(false)
const isFavoriteLoading = ref(false)

// Load history when detail changes or period changes
watch([() => props.detail.typeId, selectedPeriod], ([typeId, days]) => {
  marketStore.fetchHistory(typeId, days)
}, { immediate: true })

async function toggleFavorite(): Promise<void> {
  if (isFavoriteLoading.value) return
  isFavoriteLoading.value = true

  try {
    if (props.detail.isFavorite) {
      await marketStore.removeFavorite(props.detail.typeId)
    } else {
      await marketStore.addFavorite(props.detail.typeId)
    }
  } finally {
    isFavoriteLoading.value = false
  }
}

function handleAlertSubmit(payload: { typeId: number; direction: 'above' | 'below'; threshold: number; priceSource: string }): void {
  marketStore.createAlert(payload)
  showAlertModal.value = false
}

const hasStructureData = computed(() =>
  props.detail.structureSell !== null || props.detail.structureBuy !== null
)
</script>

<template>
  <div class="space-y-5">
    <!-- Header -->
    <div class="flex items-start justify-between gap-4">
      <div class="flex items-center gap-3">
        <img
          :src="`https://images.evetech.net/types/${detail.typeId}/icon?size=64`"
          :alt="detail.typeName"
          class="w-12 h-12 rounded-lg"
        />
        <div>
          <div class="flex items-center gap-2">
            <h3 class="text-lg font-bold text-slate-100">{{ detail.typeName }}</h3>
            <OpenInGameButton type="market" :target-id="detail.typeId" />
          </div>
          <p class="text-sm text-slate-500">{{ detail.groupName }} &middot; {{ detail.categoryName }}</p>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <!-- Favorite toggle -->
        <button
          @click="toggleFavorite"
          :disabled="isFavoriteLoading"
          :title="detail.isFavorite ? t('market.favorite.remove') : t('market.favorite.add')"
          class="p-2 rounded-lg transition-colors"
          :class="detail.isFavorite
            ? 'text-amber-400 hover:bg-amber-500/10'
            : 'text-slate-500 hover:text-amber-400 hover:bg-slate-800'"
        >
          <svg
            class="w-5 h-5"
            :fill="detail.isFavorite ? 'currentColor' : 'none'"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
          </svg>
        </button>
        <!-- Alert button -->
        <button
          @click="showAlertModal = true"
          class="flex items-center gap-1.5 px-3 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg text-sm text-slate-300 hover:text-cyan-400 transition-colors border border-slate-700"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
          </svg>
          {{ t('market.alert.create') }}
        </button>
      </div>
    </div>

    <!-- KPI row -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
      <div class="bg-slate-800/50 rounded-lg p-3 border border-slate-700/50">
        <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('market.detail.jitaSell') }}</p>
        <p class="text-lg font-bold font-mono text-cyan-400 mt-1">{{ formatIsk(detail.jitaSell) }}</p>
      </div>
      <div class="bg-slate-800/50 rounded-lg p-3 border border-slate-700/50">
        <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('market.detail.jitaBuy') }}</p>
        <p class="text-lg font-bold font-mono text-emerald-400 mt-1">{{ formatIsk(detail.jitaBuy) }}</p>
      </div>
      <div class="bg-slate-800/50 rounded-lg p-3 border border-slate-700/50">
        <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('market.detail.spread') }}</p>
        <p class="text-lg font-bold font-mono text-amber-400 mt-1">
          {{ detail.spread !== null ? detail.spread.toFixed(1) + '%' : '---' }}
        </p>
      </div>
      <div class="bg-slate-800/50 rounded-lg p-3 border border-slate-700/50">
        <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('market.detail.volume') }}</p>
        <p class="text-lg font-bold font-mono text-slate-200 mt-1">{{ formatNumber(detail.avgDailyVolume, 0) }}</p>
      </div>
      <div class="bg-slate-800/50 rounded-lg p-3 border border-slate-700/50">
        <p class="text-xs text-slate-500 uppercase tracking-wider">{{ t('market.detail.change30d') }}</p>
        <p
          class="text-lg font-bold font-mono mt-1"
          :class="detail.change30d !== null
            ? (detail.change30d >= 0 ? 'text-emerald-400' : 'text-red-400')
            : 'text-slate-500'"
        >
          <template v-if="detail.change30d !== null">
            {{ detail.change30d >= 0 ? '+' : '' }}{{ detail.change30d.toFixed(1) }}%
          </template>
          <template v-else>---</template>
        </p>
      </div>
    </div>

    <!-- Price Chart -->
    <div class="bg-slate-800/30 rounded-xl border border-slate-700/50 p-4">
      <h4 class="text-sm font-medium text-slate-300 mb-3">{{ t('market.detail.priceHistory') }}</h4>
      <PriceChart
        :data="marketStore.history"
        :selected-period="selectedPeriod"
        @update:selected-period="selectedPeriod = $event"
      />
    </div>

    <!-- Volume Chart -->
    <div class="bg-slate-800/30 rounded-xl border border-slate-700/50 p-4">
      <h4 class="text-sm font-medium text-slate-300 mb-3">{{ t('market.detail.volume') }}</h4>
      <VolumeChart :data="marketStore.history" />
    </div>

    <!-- Order Book + Comparison side by side -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <!-- Order Book -->
      <div class="bg-slate-800/30 rounded-xl border border-slate-700/50 p-4">
        <h4 class="text-sm font-medium text-slate-300 mb-3">{{ t('market.detail.orderBook') }}</h4>
        <OrderBookPanel
          :sell-orders="detail.sellOrders"
          :buy-orders="detail.buyOrders"
        />
      </div>

      <!-- Price Comparison -->
      <div
        v-if="hasStructureData"
        class="bg-slate-800/30 rounded-xl border border-slate-700/50 p-4"
      >
        <h4 class="text-sm font-medium text-slate-300 mb-3">{{ t('market.detail.comparison') }}</h4>
        <PriceComparisonTable
          :jita-sell="detail.jitaSell"
          :jita-buy="detail.jitaBuy"
          :structure-sell="detail.structureSell"
          :structure-buy="detail.structureBuy"
        />
      </div>
    </div>

    <!-- Alert Modal -->
    <AlertFormModal
      :visible="showAlertModal"
      :type-name="detail.typeName"
      :type-id="detail.typeId"
      :current-sell-price="detail.jitaSell"
      :current-buy-price="detail.jitaBuy"
      @close="showAlertModal = false"
      @submit="handleAlertSubmit"
    />
  </div>
</template>
