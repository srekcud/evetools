<script setup lang="ts">
import { ref, onMounted, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { useIndustryStore } from '@/stores/industry'
import { isValidationError } from '@/services/api'

const { t } = useI18n()
const uid = useId()
const store = useIndustryStore()

const exportCostPerM3 = ref(1200)
const loaded = ref(false)
const saving = ref(false)
const saved = ref(false)
const saveErrorKey = ref<string | null>(null)

onMounted(async () => {
  if (loaded.value) return
  await store.fetchUserSettings()
  if (store.userSettings) {
    exportCostPerM3.value = store.userSettings.exportCostPerM3
  }
  loaded.value = true
})

async function save(): Promise<void> {
  saving.value = true
  saved.value = false
  saveErrorKey.value = null
  try {
    await store.updateUserSettings({ exportCostPerM3: exportCostPerM3.value })
    saved.value = true
    setTimeout(() => { saved.value = false }, 2000)
  } catch (e) {
    saveErrorKey.value = isValidationError(e)
      ? 'industry.config.feesErrors.invalid'
      : 'industry.config.feesErrors.failed'
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
        <label :for="`${uid}-export-cost`" class="block text-xs text-slate-500 mb-1">{{ t('industry.config.exportCost') }}</label>
        <div class="relative">
          <input
            v-model.number="exportCostPerM3"
            :id="`${uid}-export-cost`"
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
    <p v-if="saveErrorKey" role="alert" data-testid="fees-save-error" class="mt-3 text-xs text-red-400">
      {{ t(saveErrorKey) }}
    </p>
  </div>
</template>
