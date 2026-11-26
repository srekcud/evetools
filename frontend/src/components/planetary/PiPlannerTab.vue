<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import PiPlannerFactoryGap from '@/components/planetary/PiPlannerFactoryGap.vue'
import PiPlannerPlanets from '@/components/planetary/PiPlannerPlanets.vue'
import PiPlannerProductions from '@/components/planetary/PiPlannerProductions.vue'
import PiPlannerRanking from '@/components/planetary/PiPlannerRanking.vue'
import PiPlannerSettings from '@/components/planetary/PiPlannerSettings.vue'
import PiPlannerSummary from '@/components/planetary/PiPlannerSummary.vue'
import PiPlannerTemplates from '@/components/planetary/PiPlannerTemplates.vue'
import PiPlannerTree from '@/components/planetary/PiPlannerTree.vue'

const { t } = useI18n()
const store = usePlanetaryPlannerStore()

onMounted(() => {
  void store.fetchCatalog()
  void store.fetchAccount()
  void store.fetchMarket()
  void store.fetchTemplates()
})
</script>

<template>
  <div class="space-y-6" data-testid="pi-planner-tab">
    <p class="max-w-3xl text-sm text-slate-400">{{ t('pi.planner.subtitle') }}</p>

    <ErrorBanner v-if="store.error" :message="store.error" @dismiss="store.clearError()" />

    <div v-if="store.isLoading && store.catalog === null" class="flex justify-center py-12">
      <LoadingSpinner size="lg" class="text-cyan-500" />
    </div>

    <template v-else-if="store.catalog !== null">
      <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
        <div class="min-w-0 space-y-6">
          <PiPlannerProductions />
          <PiPlannerSettings />
        </div>
        <div class="min-w-0 space-y-6">
          <PiPlannerSummary />
          <PiPlannerTree />
        </div>
      </div>
      <PiPlannerPlanets />
      <PiPlannerFactoryGap />
      <PiPlannerRanking />
      <PiPlannerTemplates />
    </template>
  </div>
</template>
