<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useIndustryStore } from '@/stores/industry'

const { t } = useI18n()
const store = useIndustryStore()

const brokerFeeRate = ref(3.6)
const salesTaxRate = ref(3.6)
const exportCostPerM3 = ref(1200)
const loaded = ref(false)
const saving = ref(false)
const saved = ref(false)

onMounted(async () => {
  if (loaded.value) return
  await store.fetchUserSettings()
  if (store.userSettings) {
    brokerFeeRate.value = store.userSettings.brokerFeeRate * 100
    salesTaxRate.value = store.userSettings.salesTaxRate * 100
    exportCostPerM3.value = store.userSettings.exportCostPerM3
  }
  loaded.value = true
})

async function save(): Promise<void> {
  saving.value = true
  saved.value = false
  try {
    await store.updateUserSettings({
      brokerFeeRate: brokerFeeRate.value / 100,
      salesTaxRate: salesTaxRate.value / 100,
      exportCostPerM3: exportCostPerM3.value,
    })
    saved.value = true
    setTimeout(() => { saved.value = false }, 2000)
  } catch {
    // Error handled in store
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="bg-slate-900 rounded-xl border border-slate-800 px-6 py-4">
    <h4 class="text-sm font-medium text-slate-300 mb-4">{{ t('industry.config.feesTitle') }}</h4>
    <div class="flex items-end gap-6 flex-wrap">
      <div class="w-48">
        <label class="block text-xs text-slate-500 mb-1">{{ t('industry.config.brokerFee') }}</label>
        <div class="relative">
          <input
            v-model.number="brokerFeeRate"
            type="number"
            min="0"
            max="10"
            step="0.1"
            class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm focus:outline-hidden focus:border-cyan-500 font-mono"
          />
          <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-slate-500">%</span>
        </div>
      </div>
      <div class="w-48">
        <label class="block text-xs text-slate-500 mb-1">{{ t('industry.config.salesTax') }}</label>
        <div class="relative">
          <input
            v-model.number="salesTaxRate"
            type="number"
            min="0"
            max="15"
            step="0.1"
            class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm focus:outline-hidden focus:border-cyan-500 font-mono"
          />
          <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-slate-500">%</span>
        </div>
      </div>
      <div class="w-48">
        <label class="block text-xs text-slate-500 mb-1">{{ t('industry.config.exportCost') }}</label>
        <div class="relative">
          <input
            v-model.number="exportCostPerM3"
            type="number"
            min="0"
            max="50000"
            step="100"
            class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm focus:outline-hidden focus:border-cyan-500 font-mono"
          />
          <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-slate-500">ISK/m³</span>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <button
          @click="save"
          :disabled="saving"
          class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:bg-cyan-600/50 rounded-lg text-white text-sm font-medium transition-colors disabled:cursor-not-allowed"
        >
          {{ saving ? t('common.actions.loading') : t('common.actions.save') }}
        </button>
        <Transition name="fade">
          <span v-if="saved" class="text-xs text-emerald-400">{{ t('settings.marketStructureSaved') }}</span>
        </Transition>
      </div>
    </div>
  </div>
</template>
