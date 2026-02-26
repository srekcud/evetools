<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const { t } = useI18n()
const distributionStore = useGroupDistributionStore()

const props = defineProps<{
  show: boolean
}>()

const emit = defineEmits<{
  close: []
}>()

type RateRow = {
  key: string
  label: string
  value: number
  unit: string
}

const rates = ref<RateRow[]>([
  { key: 'component_t1', label: 'Component T1', value: 250, unit: 'ISK/run' },
  { key: 'module_t1', label: 'Module T1', value: 10_000, unit: 'ISK/run' },
  { key: 'ship_t1', label: 'Ship T1', value: 100_000, unit: 'ISK/run' },
  { key: 'module_t2', label: 'Module T2', value: 100_000, unit: 'ISK/run' },
  { key: 'ship_t2', label: 'Ship T2', value: 2_500_000, unit: 'ISK/run' },
  { key: 'capital_t1', label: 'Capital T1', value: 25_000, unit: 'ISK/run' },
  { key: 'capital_t2', label: 'Capital T2', value: 125_000, unit: 'ISK/run' },
  { key: 'location_factor', label: 'Location Factor', value: 1_000_000, unit: 'ISK/day' },
])

const isSaving = ref(false)
const saveError = ref<string | null>(null)

// Load current rates from the store when modal opens
watch(() => props.show, async (newVal) => {
  if (newVal) {
    isSaving.value = false
    saveError.value = null
    await distributionStore.fetchLineRentalRates()
    if (distributionStore.lineRentalRates) {
      for (const rate of rates.value) {
        if (rate.key in distributionStore.lineRentalRates.rates) {
          rate.value = distributionStore.lineRentalRates.rates[rate.key]
        }
      }
    }
  }
})

async function handleSave(): Promise<void> {
  isSaving.value = true
  saveError.value = null
  const rateMap: Record<string, number> = {}
  for (const rate of rates.value) {
    rateMap[rate.key] = rate.value
  }
  try {
    await distributionStore.updateLineRentalRates(rateMap)
    emit('close')
  } catch (e) {
    saveError.value = e instanceof Error ? e.message : t('groupIndustry.modals.lineRental.saveError')
    isSaving.value = false
  }
}

function handleClose() {
  emit('close')
}

function handleOverlayClick() {
  emit('close')
}
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="fixed inset-0 z-50">
      <div class="modal-overlay absolute inset-0 bg-black/70 backdrop-blur-sm" @click="handleOverlayClick"></div>
      <div class="relative flex items-center justify-center min-h-screen p-4 pointer-events-none">
        <div class="modal-content bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto pointer-events-auto">

          <!-- Modal Header -->
          <div class="px-6 py-5 border-b border-slate-800 flex items-center justify-between">
            <div>
              <h2 class="text-lg font-bold text-slate-100">{{ t('groupIndustry.modals.lineRental.title') }}</h2>
            </div>
            <button
              class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 flex items-center justify-center text-slate-400 hover:text-slate-200 transition-colors"
              @click="handleClose"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <!-- Modal Body -->
          <div class="px-6 py-5">
            <p class="text-sm text-slate-400 mb-5">{{ t('groupIndustry.modals.lineRental.description') }}</p>

            <div class="bg-slate-800/50 rounded-xl border border-slate-700/50 overflow-hidden">
              <table class="w-full text-sm">
                <thead>
                  <tr class="text-xs text-slate-500 uppercase tracking-wider border-b border-slate-700">
                    <th class="text-left py-3 px-4">{{ t('groupIndustry.modals.lineRental.category') }}</th>
                    <th class="text-right py-3 px-4">{{ t('groupIndustry.modals.lineRental.rate') }}</th>
                    <th class="text-left py-3 px-4">{{ t('groupIndustry.modals.lineRental.unit') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-700/50">
                  <tr
                    v-for="rate in rates"
                    :key="rate.key"
                    class="hover:bg-slate-800/30"
                    :class="{ 'border-t-2 border-slate-600': rate.key === 'location_factor' }"
                  >
                    <td class="py-2.5 px-4 text-slate-300">{{ rate.label }}</td>
                    <td class="py-2.5 px-4 text-right">
                      <input
                        v-model.number="rate.value"
                        type="text"
                        class="w-24 bg-slate-900 border border-slate-700 rounded px-2.5 py-1 text-sm text-slate-200 font-mono text-right focus:border-cyan-500 focus:outline-none"
                        style="font-variant-numeric: tabular-nums;"
                      />
                    </td>
                    <td class="py-2.5 px-4 text-xs text-slate-500">{{ rate.unit }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <p v-if="saveError" class="mt-3 text-sm text-red-400">{{ saveError }}</p>
          </div>

          <!-- Modal Footer -->
          <div class="px-6 py-4 border-t border-slate-800 flex items-center justify-end gap-3">
            <button
              class="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 rounded-lg text-slate-300 text-sm font-medium transition-colors"
              @click="handleClose"
            >
              {{ t('common.actions.cancel') }}
            </button>
            <button
              class="btn-action px-6 py-2.5 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2"
              :disabled="isSaving"
              @click="handleSave"
            >
              <LoadingSpinner v-if="isSaving" size="sm" />
              {{ t('groupIndustry.modals.lineRental.saveDefaults') }}
            </button>
          </div>

        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
@keyframes modalFadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
@keyframes modalSlideUp {
  from { opacity: 0; transform: translateY(24px) scale(0.97); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
.modal-overlay {
  animation: modalFadeIn 0.2s ease-out;
}
.modal-content {
  animation: modalSlideUp 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
}

.btn-action {
  transition: all 0.15s ease;
}
.btn-action:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}
.btn-action:active:not(:disabled) {
  transform: translateY(0);
}
</style>
