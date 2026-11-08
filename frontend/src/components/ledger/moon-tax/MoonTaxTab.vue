<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useCorporationMiningExtractions } from '@/composables/useCorporationMiningExtractions'
import { useMoonTaxLabels, useMoonTaxMine } from '@/composables/useMoonTax'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import MoonTaxManagerPanel from './MoonTaxManagerPanel.vue'
import MoonTaxMinePanel from './MoonTaxMinePanel.vue'

type MoonTaxView = 'manager' | 'miner'

const views: MoonTaxView[] = ['manager', 'miner']

const { t } = useI18n()
const { corporations, isLoading, hasError, load } = useCorporationMiningExtractions()
const { structureLabel, corporationLabel } = useMoonTaxLabels(corporations)
const mine = useMoonTaxMine()

const view = ref<MoonTaxView>('manager')

function toggleClasses(active: boolean): string[] {
  return [
    'px-3 py-1 rounded-sm border text-xs font-medium uppercase tracking-wider transition-colors',
    active ? 'border-cyan-500/60 bg-cyan-500/10 text-cyan-300' : 'border-slate-700 text-slate-400 hover:text-slate-200',
  ]
}

function selectView(candidate: MoonTaxView): void {
  view.value = candidate
  if (candidate === 'miner') void mine.load()
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div data-moon-tax-tab class="space-y-4">
    <div>
      <h3 class="text-lg font-semibold text-white">{{ t('ledger.mining.moonTax.title') }}</h3>
      <p class="text-sm text-slate-400">{{ t('ledger.mining.moonTax.subtitle') }}</p>
    </div>

    <div class="flex flex-wrap items-center gap-2" role="group" :aria-label="t('ledger.mining.moonTax.viewLabel')">
      <button
        v-for="candidate in views"
        :key="candidate"
        type="button"
        :data-moon-tax-view="candidate"
        :aria-pressed="view === candidate"
        :class="toggleClasses(view === candidate)"
        @click="selectView(candidate)"
      >
        {{ t(`ledger.mining.moonTax.views.${candidate}`) }}
      </button>
    </div>

    <template v-if="view === 'manager'">
      <ErrorBanner v-if="hasError" :message="t('ledger.mining.moonTax.corporationsLoadError')" />

      <div v-if="isLoading && corporations.length === 0" class="flex justify-center py-12">
        <LoadingSpinner size="lg" class="text-cyan-500" />
      </div>
      <p v-else-if="!hasError && !isLoading && corporations.length === 0" data-empty-corporations class="text-sm text-slate-500">
        {{ t('ledger.mining.moonTax.noCorporation') }}
      </p>

      <MoonTaxManagerPanel
        v-for="entry in corporations"
        :key="entry.corporationId"
        :entry="entry"
        :corporation-label="corporationLabel(entry.corporationId)"
        :structure-label="structureLabel"
      />
    </template>

    <MoonTaxMinePanel
      v-else
      :statements="mine.statements.value"
      :is-loading="mine.isLoading.value"
      :has-error="mine.hasError.value"
      :structure-label="structureLabel"
      :corporation-label="corporationLabel"
    />
  </div>
</template>
