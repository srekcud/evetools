<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePlanetaryStockStore } from '@/stores/planetaryStock'

const NOTICE_CLASS = 'rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-200'
const SETTINGS_SELECTOR = '[data-testid="stock-settings"]'

const { t, te } = useI18n()
const store = usePlanetaryStockStore()

const hasNoHome = computed(() => store.stock !== null && store.stock.homeLocationId === null)
const hasUnknownConsumption = computed(() => store.rows.some(row => row.weeklyConsumption === null))

const corpReason = computed<string | null>(() => {
  const code = store.stock?.corpUnknownReason ?? null
  if (code === null) return null
  const key = `industryFactory.personalStock.unknownReason.${code}`

  return te(key) ? t(key) : t('industryFactory.personalStock.unknownReason.other')
})

function goToSettings(): void {
  document.querySelector(SETTINGS_SELECTOR)?.scrollIntoView({ behavior: 'smooth' })
}
</script>

<template>
  <div class="space-y-2" data-testid="pi-stock-banner">
    <div v-if="hasNoHome" :class="NOTICE_CLASS" role="status" data-testid="stock-banner-no-home">
      <p>{{ t('pi.stock.banner.noHome') }}</p>
      <button type="button" class="mt-1 text-xs font-medium uppercase tracking-wider text-cyan-300 hover:text-cyan-200 hover:underline" @click="goToSettings">
        {{ t('pi.stock.banner.goToSettings') }}
      </button>
    </div>
    <p v-if="corpReason !== null" :class="NOTICE_CLASS" role="status" data-testid="stock-banner-corp-unknown">
      {{ t('pi.stock.banner.corpUnknown', { reason: corpReason }) }}
    </p>
    <p v-if="hasUnknownConsumption" :class="NOTICE_CLASS" role="status" data-testid="stock-banner-consumption-unknown">
      {{ t('pi.stock.banner.consumptionUnknown') }}
    </p>
  </div>
</template>
