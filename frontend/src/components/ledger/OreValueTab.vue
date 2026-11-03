<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useFormatters } from '@/composables/useFormatters'
import { useReprocessingProfile } from '@/composables/useReprocessingProfile'
import {
  type OreValueMarket,
  type OreValueRow,
  type OreValueSide,
  type OreValueTableResponse,
} from '@/composables/useOreValueTable'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import OreValueTable from './OreValueTable.vue'
import ReprocessingProfilePanel from './ReprocessingProfilePanel.vue'

const PERCENT = 100

const props = defineProps<{
  structureId: number | null
}>()

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
const { profile, reprocessYield, hasError: hasProfileError, load: loadProfile, save: saveProfile, refresh: refreshProfile } = useReprocessingProfile()

const selectedChoiceId = ref<MarketChoiceId>('jitaSell')
const rows = ref<OreValueRow[]>([])
const usedReprocessYield = ref<number | null>(null)
const isLoading = ref(false)
const isProfileSettled = ref(false)
const errorMessage = ref<string | null>(null)
let latestRequest = 0

const selectedChoice = computed(() => marketChoicesById[selectedChoiceId.value])
const characters = computed(() => authStore.user?.characters ?? [])

function isAvailable(market: OreValueMarket): boolean {
  return market === 'jita' || props.structureId !== null
}

async function load() {
  const choice = selectedChoice.value
  if (!isAvailable(choice.market)) {
    selectedChoiceId.value = 'jitaSell'
    return
  }
  const requestedYield = reprocessYield.value
  const query = new URLSearchParams({ market: choice.market, side: choice.side })
  if (choice.market === 'structure' && props.structureId !== null) query.set('structureId', String(props.structureId))
  if (requestedYield !== null) query.set('reprocessYield', String(requestedYield))

  const request = ++latestRequest
  isLoading.value = true
  errorMessage.value = null
  try {
    const response = await apiRequest<OreValueTableResponse>(`/mining/ore-values?${query.toString()}`)
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

watch([selectedChoiceId, () => props.structureId, reprocessYield, isProfileSettled], () => {
  if (isProfileSettled.value) load()
})
onMounted(async () => {
  await loadProfile()
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
          @click="selectedChoiceId = choice.id"
        >
          {{ t(`ledger.mining.oreValue.${choice.id}`) }}
        </button>
      </div>
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
  </div>
</template>
