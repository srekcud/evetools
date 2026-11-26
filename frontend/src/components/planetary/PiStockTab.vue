<script setup lang="ts">
import { onMounted, watch } from 'vue'
import PiStockBanner from '@/components/planetary/PiStockBanner.vue'
import PiStockCoverageGrid from '@/components/planetary/PiStockCoverageGrid.vue'
import PiStockPriceGrid from '@/components/planetary/PiStockPriceGrid.vue'
import PiStockProjectNeeds from '@/components/planetary/PiStockProjectNeeds.vue'
import PiStockPurchaseList from '@/components/planetary/PiStockPurchaseList.vue'
import PiStockSettings from '@/components/planetary/PiStockSettings.vue'
import RevenueTodoPanel from '@/components/revenue/RevenueTodoPanel.vue'
import { usePlanetaryStockTodo } from '@/composables/usePlanetaryStockTodo'
import { usePlanetaryTiers } from '@/composables/usePlanetaryTiers'
import { usePlanetaryProjectNeedsStore } from '@/stores/planetaryProjectNeeds'
import { usePlanetarySettingsStore } from '@/stores/planetarySettings'
import { usePlanetaryStockStore } from '@/stores/planetaryStock'
import { usePlanetaryStockPricesStore } from '@/stores/planetaryStockPrices'

const emit = defineEmits<{ 'open-planner': [] }>()
const stockStore = usePlanetaryStockStore()
const pricesStore = usePlanetaryStockPricesStore()
const todo = usePlanetaryStockTodo()
const settingsStore = usePlanetarySettingsStore()
const projectNeedsStore = usePlanetaryProjectNeedsStore()
const { ensureCatalog } = usePlanetaryTiers()

onMounted(() => {
  stockStore.load()
  pricesStore.load()
  projectNeedsStore.load()
  ensureCatalog()
})

watch(() => settingsStore.saveRevision, () => {
  stockStore.load()
  pricesStore.load()
})

function openPlanner(): void {
  emit('open-planner')
}
</script>

<template>
  <div class="space-y-6" data-testid="pi-stock-tab">
    <p v-if="stockStore.error" class="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300" role="alert" data-testid="stock-load-error">
      {{ stockStore.error }}
    </p>
    <template v-else>
      <PiStockBanner />
      <PiStockCoverageGrid @open-planner="openPlanner" />
      <p v-if="pricesStore.error" class="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300" role="alert" data-testid="stock-prices-load-error">
        {{ pricesStore.error }}
      </p>
      <PiStockPriceGrid v-else />
      <PiStockPurchaseList />
      <RevenueTodoPanel :items="todo.items" />
    </template>
    <PiStockProjectNeeds />
    <PiStockSettings />
  </div>
</template>
