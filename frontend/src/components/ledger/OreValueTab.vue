<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useFormatters } from '@/composables/useFormatters'
import { useReprocessingProfile } from '@/composables/useReprocessingProfile'
import { useMiningValuationSettings } from '@/composables/useMiningValuationSettings'
import { useMiningValuationProfileStore } from '@/stores/miningValuationProfile'
import type { MiningValuationForm } from '@/stores/miningOperation'
import {
  type OreValueMarket,
  type OreValueRow,
  type OreValueSide,
  type OreValueTableResponse,
} from '@/composables/useOreValueTable'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import OreValueTable from './OreValueTable.vue'
import OreSalePanel from './OreSalePanel.vue'
import ReprocessingProfilePanel from './ReprocessingProfilePanel.vue'

const PERCENT = 100

const VALUATION_FORMS: MiningValuationForm[] = ['raw', 'compressed', 'reprocessed']

type MarketChoiceId = 'jitaBuy' | 'jitaSell' | 'structureBuy' | 'structureSell'

interface MarketChoice {
  id: MarketChoiceId
  market: OreValueMarket
  side: OreValueSide
}

const marketChoicesById: Record<MarketChoiceId, MarketChoice> = {
  jitaSell: { id: 'jitaSell', market: 'jita', side: 'sell' },
  jitaBuy: { id: 'jitaBuy', market: 'jita', side: 'buy' },
  structureSell: { id: 'structureSell', market: 'structure', side: 'sell' },
  structureBuy: { id: 'structureBuy', market: 'structure', side: 'buy' },
}
const marketChoices = Object.values(marketChoicesById)

const { t } = useI18n()
const { formatNumber } = useFormatters()
const authStore = useAuthStore()
const profileStore = useMiningValuationProfileStore()
const { market, side, form, exportTax, selectedStructureId, setMarketAndSide, setForm } = useMiningValuationSettings()
const { profile, reprocessYield, hasError: hasProfileError, load: loadProfile, save: saveProfile, refresh: refreshProfile } = useReprocessingProfile()

const rows = ref<OreValueRow[]>([])
const usedReprocessYield = ref<number | null>(null)
const isLoading = ref(false)
const isProfileSettled = ref(false)
const errorMessage = ref<string | null>(null)
let latestRequest = 0

const selectedChoice = computed(() => marketChoices.find(choice => choice.market === market.value && choice.side === side.value) ?? marketChoicesById.jitaBuy)
const selectedChoiceId = computed(() => selectedChoice.value.id)
const characters = computed(() => authStore.user?.characters ?? [])

function isAvailable(choiceMarket: OreValueMarket): boolean {
  return choiceMarket === 'jita' || selectedStructureId.value !== null
}

function onFormChange(event: Event): void {
  setForm((event.target as HTMLSelectElement).value as MiningValuationForm)
}

async function load() {
  const requestedYield = reprocessYield.value
  const query = new URLSearchParams()
  if (requestedYield !== null) query.set('reprocessYield', String(requestedYield))
  const queryString = query.toString()

  const request = ++latestRequest
  isLoading.value = true
  errorMessage.value = null
  try {
    const response = await apiRequest<OreValueTableResponse>(queryString === '' ? '/mining/ore-values' : `/mining/ore-values?${queryString}`)
    if (request !== latestRequest) return
    rows.value = response.rows
    usedReprocessYield.value = response.reprocessYieldKnown ? requestedYield : null
  } catch (e) {
    if (request !== latestRequest) return
    console.error('Failed to load the ore value table:', e)
    rows.value = []
    errorMessage.value = t('ledger.mining.oreValue.loadError')
  } finally {
    if (request === latestRequest) isLoading.value = false
  }
}

watch([market, side, selectedStructureId, exportTax, reprocessYield, isProfileSettled], () => {
  if (isProfileSettled.value) load()
})
onMounted(async () => {
  await Promise.all([loadProfile(), profileStore.load()])
  isProfileSettled.value = true
})
</script>

<template>
  <div class="space-y-4">
    <div>
      <h3 class="text-lg font-semibold text-white">{{ t('ledger.mining.oreValue.title') }}</h3>
      <p class="text-sm text-slate-400">{{ t('ledger.mining.oreValue.subtitle') }}</p>
    </div>

    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
      <div class="flex flex-wrap items-center gap-2" role="group" :aria-label="t('ledger.mining.oreValue.marketLabel')">
        <span class="text-xs uppercase tracking-wider text-slate-500">{{ t('ledger.mining.oreValue.marketLabel') }}</span>
        <button
          v-for="choice in marketChoices"
          :key="choice.id"
          type="button"
          :aria-pressed="selectedChoiceId === choice.id"
          :disabled="!isAvailable(choice.market)"
          :title="isAvailable(choice.market) ? undefined : t('ledger.mining.oreValue.structureRequired')"
          :class="[
            'px-3 py-1 rounded-sm border text-xs font-medium uppercase tracking-wider transition-colors disabled:opacity-40 disabled:cursor-not-allowed',
            selectedChoiceId === choice.id ? 'border-cyan-500/60 bg-cyan-500/10 text-cyan-300' : 'border-slate-700 text-slate-400 hover:text-slate-200',
          ]"
          @click="setMarketAndSide(choice.market, choice.side)"
        >
          {{ t(`ledger.mining.oreValue.${choice.id}`) }}
        </button>
      </div>
      <label class="flex items-center gap-2">
        <span class="text-xs uppercase tracking-wider text-slate-500">{{ t('miningOperation.valuation.form.label') }}</span>
        <select
          data-valuation-form
          :value="form"
          class="rounded-sm border border-slate-700 bg-slate-800/50 px-2 py-1 text-xs font-medium uppercase tracking-wider text-slate-200 focus:outline-hidden focus:border-cyan-500/50"
          @change="onFormChange"
        >
          <option v-for="valuationForm in VALUATION_FORMS" :key="valuationForm" :value="valuationForm">
            {{ t(`miningOperation.valuation.form.${valuationForm}`) }}
          </option>
        </select>
      </label>
      <p v-if="usedReprocessYield !== null" class="text-xs text-slate-400">
        {{ t('ledger.mining.oreValue.yieldKnown', { percent: formatNumber(usedReprocessYield * PERCENT) }) }}
      </p>
      <p v-else-if="isProfileSettled && !isLoading && errorMessage === null" data-reprocess-yield-missing class="text-xs text-amber-500">
        <span aria-hidden="true">⚠ </span>{{ t('ledger.mining.oreValue.yieldUnknown') }}
      </p>
      <span class="text-xs text-slate-500">{{ t('ledger.mining.oreValue.unit') }}</span>
    </div>

    <ErrorBanner v-if="errorMessage !== null" :message="errorMessage" @dismiss="errorMessage = null" />
    <ErrorBanner v-if="hasProfileError" :message="t('ledger.mining.reprocessingProfile.requestError')" />

    <ReprocessingProfilePanel :profile="profile" :characters="characters" @save="saveProfile" @refresh="refreshProfile" />

    <div v-if="isLoading && rows.length === 0" class="flex justify-center py-12">
      <LoadingSpinner size="lg" class="text-cyan-500" />
    </div>
    <p v-else-if="!isLoading && errorMessage === null && rows.length === 0" class="text-sm text-slate-500">
      {{ t('ledger.mining.oreValue.empty') }}
    </p>
    <OreValueTable v-else-if="rows.length > 0" :rows="rows" :class="{ 'opacity-60': isLoading }" />

    <OreSalePanel />
  </div>
</template>
