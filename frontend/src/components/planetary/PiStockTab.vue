<script setup lang="ts">
import PiStockBanner from '@/components/planetary/PiStockBanner.vue'
import PiStockCoverageGrid from '@/components/planetary/PiStockCoverageGrid.vue'
import PiStockPurchaseList from '@/components/planetary/PiStockPurchaseList.vue'
import PiStockSettings from '@/components/planetary/PiStockSettings.vue'

const emit = defineEmits<{ 'open-planner': [] }>()

function openPlanner(): void {
  emit('open-planner')
}
</script>

<template>
  <div class="space-y-6" data-testid="pi-stock-tab">
    <PiStockBanner />
    <PiStockCoverageGrid @open-planner="openPlanner" />
    <PiStockPurchaseList />
    <PiStockSettings />
  </div>
</template>
