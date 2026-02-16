<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ProfitSettings as ProfitSettingsType } from '@/stores/profitTracker'

const props = defineProps<{
  settings: ProfitSettingsType | null
  show: boolean
}>()

const emit = defineEmits<{
  close: []
  save: [settings: Partial<ProfitSettingsType>]
}>()

const { t } = useI18n()

const taxRate = ref(3.6)
const costSource = ref('market')
const isSaving = ref(false)

watch(() => props.settings, (s) => {
  if (s) {
    taxRate.value = s.salesTaxRate
    costSource.value = s.defaultCostSource
  }
}, { immediate: true })

async function handleSave(): Promise<void> {
  isSaving.value = true
  try {
    emit('save', {
      salesTaxRate: taxRate.value,
      defaultCostSource: costSource.value,
    })
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="show"
      class="fixed inset-0 z-50 flex items-center justify-center"
    >
      <!-- Backdrop -->
      <div
        class="absolute inset-0 bg-slate-950/80"
        @click="emit('close')"
      ></div>

      <!-- Modal -->
      <div class="relative bg-slate-900 rounded-2xl border border-cyan-500/30 shadow-2xl shadow-cyan-500/10 w-full max-w-md mx-4">
        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
          <h3 class="text-lg font-semibold text-slate-100">{{ t('profitTracker.settings.title') }}</h3>
          <button
            @click="emit('close')"
            class="p-1 hover:bg-slate-800 rounded-lg transition-colors"
          >
            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <!-- Content -->
        <div class="p-6 space-y-6">
          <!-- Sales Tax Rate -->
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-2">
              {{ t('profitTracker.settings.taxRate') }}
            </label>
            <div class="relative">
              <input
                v-model.number="taxRate"
                type="number"
                min="0"
                max="100"
                step="0.1"
                class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-sm text-slate-200 focus:outline-none focus:border-cyan-500 transition-colors font-mono"
              />
              <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-slate-500">%</span>
            </div>
          </div>

          <!-- Default Cost Source -->
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-2">
              {{ t('profitTracker.settings.costSource') }}
            </label>
            <select
              v-model="costSource"
              class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-sm text-slate-200 focus:outline-none focus:border-cyan-500 transition-colors"
            >
              <option value="market">{{ t('profitTracker.settings.costSourceMarket') }}</option>
              <option value="project">{{ t('profitTracker.settings.costSourceProject') }}</option>
              <option value="manual">{{ t('profitTracker.settings.costSourceManual') }}</option>
            </select>
          </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 border-t border-slate-800 flex justify-end gap-3">
          <button
            @click="emit('close')"
            class="px-4 py-2 bg-slate-800 hover:bg-slate-700 rounded-lg text-slate-400 text-sm font-medium transition-colors"
          >
            {{ t('common.actions.cancel') }}
          </button>
          <button
            @click="handleSave"
            :disabled="isSaving"
            class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium transition-colors disabled:opacity-50"
          >
            {{ isSaving ? t('common.actions.loading') : t('profitTracker.settings.save') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
