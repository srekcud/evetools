<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  MAX_TAX_RATE,
  MIN_TAX_RATE,
  RATE_FIELDS,
  TAX_RATE_STEP,
  WALLET_DIVISIONS,
  type MoonTaxRateField,
  type MoonTaxRefinerySetting,
  type MoonTaxRefineryPayload,
} from '@/composables/useMoonTax'

const props = defineProps<{
  structureId: number
  name: string
  setting: MoonTaxRefinerySetting | null
  isSaving: boolean
  isSaved: boolean
  errorMessage: string | null
}>()

const emit = defineEmits<{
  save: [payload: MoonTaxRefineryPayload]
}>()

type RefineryDraft = Record<MoonTaxRateField, number | ''> & {
  openToAlliance: boolean
  period: string
  walletDivision: number | ''
}

const PERIODS = ['weekly', 'monthly']
const RATE_PREFIX_LENGTH = 'rate'.length

const { t } = useI18n()

function draftFrom(setting: MoonTaxRefinerySetting | null): RefineryDraft {
  return {
    openToAlliance: setting?.openToAlliance ?? false,
    period: setting?.period ?? 'weekly',
    rateR4: setting?.rateR4 ?? '',
    rateR8: setting?.rateR8 ?? '',
    rateR16: setting?.rateR16 ?? '',
    rateR32: setting?.rateR32 ?? '',
    rateR64: setting?.rateR64 ?? '',
    walletDivision: setting?.walletDivision ?? '',
  }
}

const draft = reactive<RefineryDraft>(draftFrom(props.setting))

watch(() => props.setting, setting => Object.assign(draft, draftFrom(setting)))

const isComplete = computed(() => RATE_FIELDS.every(field => draft[field] !== '') && draft.walletDivision !== '')

function rankLabel(field: MoonTaxRateField): string {
  return field.slice(RATE_PREFIX_LENGTH)
}

function submit(): void {
  if (!isComplete.value) return
  emit('save', {
    openToAlliance: draft.openToAlliance,
    period: draft.period,
    rateR4: Number(draft.rateR4),
    rateR8: Number(draft.rateR8),
    rateR16: Number(draft.rateR16),
    rateR32: Number(draft.rateR32),
    rateR64: Number(draft.rateR64),
    walletDivision: Number(draft.walletDivision),
  })
}
</script>

<template>
  <div :data-refinery="structureId" class="space-y-3 rounded-xl border border-slate-800 bg-slate-900 p-4">
    <h5 class="truncate text-sm font-semibold text-white" :title="name">{{ name }}</h5>

    <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
      <label class="flex items-center gap-2 text-sm text-slate-300">
        <input v-model="draft.openToAlliance" data-field="openToAlliance" type="checkbox" class="h-4 w-4 rounded-sm border-slate-600 bg-slate-800 text-cyan-500" />
        {{ t('ledger.mining.moonTax.refinery.openToAlliance') }}
      </label>

      <label class="space-y-1 text-xs uppercase tracking-wider text-slate-400">
        <span class="block">{{ t('ledger.mining.moonTax.refinery.period') }}</span>
        <select
          v-model="draft.period"
          data-field="period"
          class="rounded-sm border border-slate-700 bg-slate-800 px-2 py-1 text-sm normal-case tracking-normal text-slate-200"
        >
          <option v-for="period in PERIODS" :key="period" :value="period">{{ t(`ledger.mining.moonTax.refinery.periods.${period}`) }}</option>
        </select>
      </label>

      <label class="space-y-1 text-xs uppercase tracking-wider text-slate-400">
        <span class="block">{{ t('ledger.mining.moonTax.refinery.walletDivision') }}</span>
        <select
          v-model.number="draft.walletDivision"
          data-field="walletDivision"
          class="rounded-sm border border-slate-700 bg-slate-800 px-2 py-1 text-sm normal-case tracking-normal text-slate-200"
        >
          <option value="" disabled>{{ t('ledger.mining.moonTax.refinery.chooseDivision') }}</option>
          <option v-for="division in WALLET_DIVISIONS" :key="division" :value="division">{{ division }}</option>
        </select>
      </label>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
      <label v-for="field in RATE_FIELDS" :key="field" class="space-y-1 text-xs uppercase tracking-wider text-slate-400">
        <span class="block">{{ t('ledger.mining.moonTax.refinery.rate', { rank: rankLabel(field) }) }}</span>
        <input
          v-model.number="draft[field]"
          :data-field="field"
          type="number"
          :min="MIN_TAX_RATE"
          :max="MAX_TAX_RATE"
          :step="TAX_RATE_STEP"
          class="w-full rounded-sm border border-slate-700 bg-slate-800 px-2 py-1 text-right text-sm text-slate-200 focus:border-cyan-500/60 focus:outline-hidden"
        />
      </label>
    </div>

    <div class="flex flex-wrap items-center gap-3">
      <button
        type="button"
        data-save
        :disabled="!isComplete || isSaving"
        class="rounded-sm border border-cyan-500/60 bg-cyan-500/10 px-3 py-1 text-xs font-medium uppercase tracking-wider text-cyan-300 transition-colors hover:bg-cyan-500/20 disabled:cursor-not-allowed disabled:opacity-40"
        @click="submit"
      >
        {{ isSaving ? t('ledger.mining.moonTax.refinery.saving') : t('ledger.mining.moonTax.refinery.save') }}
      </button>
      <p v-if="isSaved" data-form-saved role="status" class="text-sm text-emerald-400">{{ t('ledger.mining.moonTax.refinery.saved') }}</p>
    </div>

    <p v-if="errorMessage !== null" data-form-error role="alert" class="text-sm text-red-400">{{ errorMessage }}</p>
  </div>
</template>
