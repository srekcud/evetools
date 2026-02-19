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
import { useEveImages } from '@/composables/useEveImages'

const { getTypeIconUrl, onImageError } = useEveImages()

const props = defineProps<{
  detail: TypeDetailType
}>()

const emit = defineEmits<{
  back: []
}>()

const { t } = useI18n()
const marketStore = useMarketStore()
const { formatIsk, formatNumber } = useFormatters()

const selectedPeriod = ref(30)
const showAlertModal = ref(false)
const isFavoriteLoading = ref(false)
const chartSource = ref<'jita' | 'structure'>('jita')

const structureName = computed(() => {
  return props.detail.structureName || t('market.detail.sourceStructure')
})

function switchSource(source: 'jita' | 'structure'): void {
  chartSource.value = source
  marketStore.fetchHistory(props.detail.typeId, selectedPeriod.value, source)
}

// Reset to jita when switching to a different item
watch(() => props.detail.typeId, (typeId) => {
  chartSource.value = 'jita'
  marketStore.fetchHistory(typeId, selectedPeriod.value, 'jita')
}, { immediate: true })

// Refetch with current source when period changes
watch(selectedPeriod, (days) => {
  marketStore.fetchHistory(props.detail.typeId, days, chartSource.value)
})

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

// KPI computed values
const spreadIsk = computed(() => {
  if (props.detail.jitaSell !== null && props.detail.jitaBuy !== null) {
    return props.detail.jitaSell - props.detail.jitaBuy
  }
  return null
})

const spreadPercent = computed(() => {
  if (props.detail.spread != null) {
    return props.detail.spread.toFixed(1) + '%'
  }
  return null
})

const change30dIsPositive = computed(() => {
  return props.detail.change30d !== null && props.detail.change30d >= 0
})

const change30dIsNegative = computed(() => {
  return props.detail.change30d !== null && props.detail.change30d < 0
})

// Estimate the "from" price for 30d change sublabel
const price30dAgo = computed(() => {
  if (props.detail.change30d === null || props.detail.jitaSell === null) return null
  if (props.detail.change30d === 0) return props.detail.jitaSell
  return props.detail.jitaSell / (1 + props.detail.change30d / 100)
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center gap-4 mb-6">
      <!-- Back button -->
      <button
        @click="emit('back')"
        class="p-2 bg-slate-800/50 border border-slate-700/50 rounded-lg hover:border-cyan-500/30 text-slate-400 hover:text-cyan-400 transition-colors"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
      </button>

      <!-- Large icon -->
      <img
        :src="getTypeIconUrl(detail.typeId, 64)"
        :alt="detail.typeName"
        class="w-14 h-14 rounded-lg ring-2 ring-slate-700/50"
        @error="onImageError"
      />

      <div class="flex-1">
        <div class="flex items-center gap-3">
          <h2 class="text-xl font-bold text-slate-100">{{ detail.typeName }}</h2>
          <!-- Favorite star -->
          <button
            @click="toggleFavorite"
            :disabled="isFavoriteLoading"
            :title="detail.isFavorite ? t('market.favorite.remove') : t('market.favorite.add')"
            class="transition-colors"
            :class="detail.isFavorite
              ? 'text-amber-400 hover:text-amber-300'
              : 'text-slate-600 hover:text-amber-400'"
          >
            <svg
              class="w-5 h-5"
              :fill="detail.isFavorite ? 'currentColor' : 'none'"
              stroke="currentColor"
              viewBox="0 0 20 20"
            >
              <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
            </svg>
          </button>
          <OpenInGameButton type="market" :target-id="detail.typeId" />
        </div>
        <!-- Breadcrumb -->
        <div class="flex items-center gap-2 text-xs text-slate-500 mt-1">
          <span>{{ detail.categoryName }}</span>
          <span class="text-slate-700">&rsaquo;</span>
          <span>{{ detail.groupName }}</span>
          <span class="text-slate-700">&rsaquo;</span>
          <span class="text-slate-400">{{ detail.typeName }}</span>
          <span class="ml-2 px-1.5 py-0.5 bg-slate-800/50 border border-slate-700/50 rounded text-slate-500 font-mono">ID: {{ detail.typeId }}</span>
        </div>
      </div>

      <!-- Alert button -->
      <button
        @click="showAlertModal = true"
        class="flex items-center gap-2 px-4 py-2.5 bg-slate-800/50 border border-slate-700/50 rounded-lg hover:border-amber-500/30 hover:text-amber-400 text-slate-400 transition-colors text-sm"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
        </svg>
        {{ t('market.alert.setAlert') }}
      </button>
    </div>

    <!-- KPI Cards -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
      <!-- Best Sell -->
      <div class="bg-slate-900/50 border border-slate-800/50 rounded-xl p-4">
        <div class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('market.detail.bestSell') }}</div>
        <div class="text-lg font-bold font-mono text-cyan-400">
          {{ formatIsk(detail.jitaSell) }}
          <span v-if="detail.jitaSell !== null" class="text-xs text-slate-500">ISK</span>
        </div>
        <div class="text-xs text-slate-500 mt-1">{{ t('market.detail.location') }}</div>
      </div>

      <!-- Best Buy -->
      <div class="bg-slate-900/50 border border-slate-800/50 rounded-xl p-4">
        <div class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('market.detail.bestBuy') }}</div>
        <div class="text-lg font-bold font-mono text-amber-400">
          {{ formatIsk(detail.jitaBuy) }}
          <span v-if="detail.jitaBuy !== null" class="text-xs text-slate-500">ISK</span>
        </div>
        <div class="text-xs text-slate-500 mt-1">{{ t('market.detail.location') }}</div>
      </div>

      <!-- Spread -->
      <div class="bg-slate-900/50 border border-slate-800/50 rounded-xl p-4">
        <div class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('market.detail.spread') }}</div>
        <div class="text-lg font-bold font-mono text-slate-200">
          <template v-if="spreadIsk !== null">
            {{ formatIsk(spreadIsk) }}
            <span class="text-xs text-slate-500">ISK</span>
          </template>
          <template v-else>---</template>
        </div>
        <div class="text-xs text-amber-400/70 mt-1">
          <template v-if="spreadPercent">{{ spreadPercent }}</template>
        </div>
      </div>

      <!-- Avg Daily Vol -->
      <div class="bg-slate-900/50 border border-slate-800/50 rounded-xl p-4">
        <div class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('market.detail.volume') }}</div>
        <div class="text-lg font-bold font-mono text-slate-200">
          {{ formatNumber(detail.avgDailyVolume, 0) }}
        </div>
        <div class="text-xs text-slate-500 mt-1">{{ t('market.detail.avgDaily') }}</div>
      </div>

      <!-- 30d Change -->
      <div class="bg-slate-900/50 border border-slate-800/50 rounded-xl p-4">
        <div class="text-xs text-slate-500 uppercase tracking-wider mb-1">{{ t('market.detail.change30d') }}</div>
        <div
          class="text-lg font-bold font-mono flex items-center gap-1"
          :class="{
            'text-emerald-400': change30dIsPositive,
            'text-red-400': change30dIsNegative,
            'text-slate-500': detail.change30d === null
          }"
        >
          <template v-if="detail.change30d !== null">
            <!-- Arrow icon -->
            <svg v-if="change30dIsPositive" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" />
            </svg>
            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
            </svg>
            {{ detail.change30d >= 0 ? '+' : '' }}{{ detail.change30d.toFixed(1) }}%
          </template>
          <template v-else>---</template>
        </div>
        <div v-if="price30dAgo !== null && detail.jitaSell !== null" class="text-xs text-slate-500 mt-1">
          {{ formatIsk(price30dAgo) }} &rarr; {{ formatIsk(detail.jitaSell) }}
        </div>
      </div>
    </div>

    <!-- Structure prices row -->
    <div v-if="detail.hasPreferredStructure" class="grid grid-cols-2 gap-4 mb-6">
      <!-- Structure Sell -->
      <div class="bg-slate-900/50 border border-indigo-500/20 rounded-xl p-4">
        <div class="text-xs text-slate-500 uppercase tracking-wider mb-1">
          <span class="inline-flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
            {{ t('market.detail.structureSell') }}
          </span>
        </div>
        <div class="text-lg font-bold font-mono" :class="detail.structureSell !== null ? 'text-cyan-400' : 'text-slate-600'">
          <template v-if="detail.structureSell !== null">
            {{ formatIsk(detail.structureSell) }}
            <span class="text-xs text-slate-500">ISK</span>
          </template>
          <template v-else>{{ t('market.detail.noOrders') }}</template>
        </div>
        <div class="text-xs text-slate-500 mt-1">{{ structureName }}</div>
      </div>

      <!-- Structure Buy -->
      <div class="bg-slate-900/50 border border-indigo-500/20 rounded-xl p-4">
        <div class="text-xs text-slate-500 uppercase tracking-wider mb-1">
          <span class="inline-flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
            {{ t('market.detail.structureBuy') }}
          </span>
        </div>
        <div class="text-lg font-bold font-mono" :class="detail.structureBuy !== null ? 'text-amber-400' : 'text-slate-600'">
          <template v-if="detail.structureBuy !== null">
            {{ formatIsk(detail.structureBuy) }}
            <span class="text-xs text-slate-500">ISK</span>
          </template>
          <template v-else>{{ t('market.detail.noOrders') }}</template>
        </div>
        <div class="text-xs text-slate-500 mt-1">{{ structureName }}</div>
      </div>
    </div>

    <!-- Source Toggle (shown only if preferred structure configured) -->
    <div v-if="detail.hasPreferredStructure" class="flex items-center gap-2 mb-6">
      <button
        @click="switchSource('jita')"
        class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors border"
        :class="chartSource === 'jita'
          ? 'bg-cyan-500/20 text-cyan-400 border-cyan-500/30'
          : 'bg-slate-800 text-slate-400 border-slate-700 hover:border-slate-600'"
      >
        {{ t('market.detail.sourceJita') }}
      </button>
      <button
        @click="switchSource('structure')"
        class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors border"
        :class="chartSource === 'structure'
          ? 'bg-indigo-500/20 text-indigo-400 border-indigo-500/30'
          : 'bg-slate-800 text-slate-400 border-slate-700 hover:border-slate-600'"
      >
        {{ structureName }}
      </button>
    </div>

    <!-- Price Chart -->
    <div class="bg-slate-900/50 border border-slate-800/50 rounded-xl p-5 mb-6">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-sm font-medium text-slate-300">{{ t('market.detail.priceHistory') }}</h3>
      </div>
      <PriceChart
        :data="marketStore.history"
        :selected-period="selectedPeriod"
        :source="chartSource"
        @update:selected-period="selectedPeriod = $event"
      />
    </div>

    <!-- Volume Chart -->
    <div class="bg-slate-900/50 border border-slate-800/50 rounded-xl p-5 mb-6">
      <h3 class="text-sm font-medium text-slate-300 mb-4">{{ t('market.detail.dailyVolume') }}</h3>
      <VolumeChart :data="marketStore.history" :source="chartSource" />
    </div>

    <!-- Order Book + Comparison side by side -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
      <!-- Order Book -->
      <div class="bg-slate-900/50 border border-slate-800/50 rounded-xl overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-800/50">
          <h3 class="text-sm font-medium text-slate-300">{{ t('market.detail.orderBook') }}</h3>
        </div>
        <OrderBookPanel
          :sell-orders="detail.sellOrders"
          :buy-orders="detail.buyOrders"
          :structure-sell-orders="detail.structureSellOrders"
          :structure-buy-orders="detail.structureBuyOrders"
          :has-structure="detail.hasPreferredStructure"
          :structure-name="detail.structureName ?? undefined"
        />
      </div>

      <!-- Price Comparison -->
      <div
        v-if="detail.hasPreferredStructure"
        class="bg-slate-900/50 border border-slate-800/50 rounded-xl overflow-hidden"
      >
        <div class="px-4 py-3 border-b border-slate-800/50">
          <h3 class="text-sm font-medium text-slate-300">{{ t('market.detail.jitaVsStructure') }}</h3>
        </div>
        <PriceComparisonTable
          :jita-sell="detail.jitaSell"
          :jita-buy="detail.jitaBuy"
          :structure-sell="detail.structureSell"
          :structure-buy="detail.structureBuy"
          :structure-name="detail.structureName ?? undefined"
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
      :has-structure="detail.hasPreferredStructure"
      :structure-name="detail.structureName"
      :current-structure-sell-price="detail.structureSell"
      :current-structure-buy-price="detail.structureBuy"
      @close="showAlertModal = false"
      @submit="handleAlertSubmit"
    />
  </div>
</template>
