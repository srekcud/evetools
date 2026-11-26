<script setup lang="ts">
import { onMounted, watch } from 'vue'
import PiStockBanner from '@/components/planetary/PiStockBanner.vue'
import PiStockCoverageGrid from '@/components/planetary/PiStockCoverageGrid.vue'
import PiStockProjectNeeds from '@/components/planetary/PiStockProjectNeeds.vue'
import PiStockPurchaseList from '@/components/planetary/PiStockPurchaseList.vue'
import PiStockSettings from '@/components/planetary/PiStockSettings.vue'
import { usePlanetarySettingsStore } from '@/stores/planetarySettings'
import { usePlanetaryStockStore } from '@/stores/planetaryStock'

const emit = defineEmits<{ 'open-planner': [] }>()
const stockStore = usePlanetaryStockStore()
const settingsStore = usePlanetarySettingsStore()

onMounted(() => stockStore.load())

watch(() => settingsStore.saveRevision, () => stockStore.load())

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
      <PiStockPurchaseList />
    </template>
    <PiStockProjectNeeds />
    <PiStockSettings />
  </div>
</template>
