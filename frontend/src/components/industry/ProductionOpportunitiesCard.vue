<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from '@/components/common/DataTable.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import SellMarketUnknownValue from '@/components/industry/SellMarketUnknownValue.vue'
import { useFormatters } from '@/composables/useFormatters'
import { useOpportunitiesStore } from '@/stores/industry/opportunities'
import type { OpportunityMarket, OpportunitySort, ProductionOpportunity } from '@/stores/industry/types'

const CAPITAL_STORAGE_KEY = 'industry.opportunities.capital'
const MARKETS: OpportunityMarket[] = ['all', 'jita', 'capital']
const SORTS: OpportunitySort[] = ['week', 'hour']
const MARGIN_DECIMALS = 1

const { t } = useI18n()
const { formatIskFull, formatNumber } = useFormatters()
const store = useOpportunitiesStore()

const market = ref<OpportunityMarket>('all')
const sort = ref<OpportunitySort>('week')
const capital = ref<number | null>(readStoredCapital())
const capitalInput = ref<number | string>(capital.value ?? '')

function readStoredCapital(): number | null {
  try {
    const stored = Number(localStorage.getItem(CAPITAL_STORAGE_KEY))
    return Number.isInteger(stored) && stored > 0 ? stored : null
  } catch {
    return null
  }
}

function storeCapital(value: number | null): void {
  try {
    if (value === null) localStorage.removeItem(CAPITAL_STORAGE_KEY)
    else localStorage.setItem(CAPITAL_STORAGE_KEY, String(value))
  } catch {
    return
  }
}

function applyCapital(): void {
  const entered = Number(capitalInput.value)
  const value = capitalInput.value !== '' && Number.isInteger(entered) && entered > 0 ? entered : null
  capitalInput.value = value ?? ''
  capital.value = value
  storeCapital(value)
}

watch(
  [market, sort, capital],
  ([chosenMarket, chosenSort, chosenCapital]) => {
    void store.fetchOpportunities(chosenMarket, chosenSort, chosenCapital)
  },
  { immediate: true },
)

function stateOf(opportunity: ProductionOpportunity): 'blocked' | 'ok' {
  return opportunity.missingSkills.length > 0 ? 'blocked' : 'ok'
}

function requiredSkills(opportunity: ProductionOpportunity): string {
  return opportunity.missingSkills.map(skill => `${skill.name ?? skill.skillId} ${skill.level}`).join(', ')
}

function rowKey(opportunity: ProductionOpportunity): string {
  return `${opportunity.typeId}-${opportunity.market}`
}
</script>

<template>
  <section class="bg-slate-900 rounded-xl border border-slate-800" data-testid="production-opportunities-card">
    <header class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-slate-800">
      <h2 class="font-display text-base font-bold uppercase tracking-[0.14em] text-slate-100">{{ t('industry.opportunities.title') }}</h2>
      <div class="ml-auto flex flex-wrap items-center gap-3">
        <div class="flex rounded-sm border border-slate-700 overflow-hidden" role="group">
          <button
            v-for="option in MARKETS"
            :key="option"
            type="button"
            :data-testid="`opportunities-market-${option}`"
            :aria-pressed="market === option"
            :disabled="option === 'capital' && store.result !== null && store.result.capitalStructureName === null"
            class="px-3 py-1 text-xs uppercase tracking-wider disabled:opacity-40 disabled:cursor-not-allowed"
            :class="market === option ? 'bg-cyan-500/20 text-cyan-300' : 'text-slate-400 hover:bg-slate-800'"
            @click="market = option"
          >{{ t(`industry.opportunities.market.${option}`) }}</button>
        </div>
        <div class="flex rounded-sm border border-slate-700 overflow-hidden" role="group">
          <button
            v-for="option in SORTS"
            :key="option"
            type="button"
            :data-testid="`opportunities-sort-${option}`"
            :aria-pressed="sort === option"
            class="px-3 py-1 text-xs uppercase tracking-wider"
            :class="sort === option ? 'bg-cyan-500/20 text-cyan-300' : 'text-slate-400 hover:bg-slate-800'"
            @click="sort = option"
          >{{ t(`industry.opportunities.sort.${option}`) }}</button>
        </div>
      </div>
    </header>

    <div class="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 border-b border-slate-800 text-xs text-slate-400">
      <label class="flex items-center gap-2 uppercase tracking-wider">
        {{ t('industry.opportunities.capital.label') }}
        <input
          v-model="capitalInput"
          data-testid="opportunities-capital-input"
          type="number"
          min="1"
          step="1"
          :placeholder="t('industry.opportunities.capital.placeholder')"
          class="w-36 px-2 py-1 bg-slate-900 border border-slate-700 rounded-sm text-right font-mono text-slate-100 normal-case tabular-nums focus:border-cyan-500 focus:outline-hidden"
          @change="applyCapital"
        />
        {{ t('industry.opportunities.capital.unit') }}
      </label>
      <span v-if="store.result !== null && store.result.freeSlots !== null" data-testid="opportunities-free-slots">
        {{ t('industry.opportunities.freeSlots', { count: store.result.freeSlots.manufacturing }) }}
      </span>
    </div>

    <p v-if="store.error" data-testid="opportunities-error" class="px-4 py-3 text-sm text-red-400">{{ store.error }}</p>

    <div v-else-if="store.loading && !store.result" class="flex justify-center py-6">
      <LoadingSpinner />
    </div>

    <template v-else-if="store.result">
      <p v-if="!store.result.salesFeesKnown" data-testid="opportunities-fees-unknown" class="px-4 py-2 text-sm text-violet-300">
        {{ t('industry.opportunities.feesUnknown') }}
      </p>
      <p v-if="store.result.unknownProductCount > 0" data-testid="opportunities-unknown-count" class="px-4 py-2 text-sm text-violet-300">
        {{ t('industry.opportunities.unknownCount', { count: store.result.unknownProductCount }) }}
      </p>

      <p v-if="store.result.rows.length === 0" data-testid="opportunities-empty" class="px-4 py-4 text-sm text-slate-500">{{ t('industry.opportunities.empty') }}</p>

      <DataTable v-else>
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-700 text-slate-400 text-xs uppercase tracking-wider">
              <th class="text-left py-2.5 px-4">{{ t('industry.opportunities.columns.product') }}</th>
              <th class="text-left py-2.5 px-3">{{ t('industry.opportunities.columns.market') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.opportunities.columns.priceVolume') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.opportunities.columns.margin') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.opportunities.columns.quantity') }}</th>
              <th class="text-right py-2.5 px-3">{{ t('industry.opportunities.columns.profit') }}</th>
              <th class="text-left py-2.5 px-4">{{ t('industry.opportunities.columns.advice') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800">
            <tr
              v-for="opportunity in store.result.rows"
              :key="rowKey(opportunity)"
              :data-testid="`opportunity-row-${opportunity.typeId}-${opportunity.market}`"
              :data-state="stateOf(opportunity)"
              class="hover:bg-slate-800/50"
              :class="stateOf(opportunity) === 'ok' ? '' : 'opacity-60'"
            >
              <td class="py-2.5 px-4 text-slate-200">
                <div class="font-semibold">{{ opportunity.typeName ?? opportunity.typeId }}</div>
                <div class="text-xs text-slate-500">{{ opportunity.categoryLabel }}</div>
              </td>
              <td class="py-2.5 px-3">
                <span class="px-2 py-0.5 rounded-sm border text-xs" :class="opportunity.market === 'jita' ? 'border-slate-600 text-slate-300' : 'border-cyan-500/40 text-cyan-300'">
                  {{ t(`industry.opportunities.market.${opportunity.market}`) }}
                </span>
              </td>
              <td class="py-2.5 px-3 text-right font-mono tabular-nums text-slate-100">
                {{ formatIskFull(opportunity.sellPrice) }}
                <div class="text-xs text-slate-500">{{ t('industry.opportunities.perDay', { count: formatNumber(opportunity.dailyVolume, 0) }) }}</div>
              </td>
              <td class="py-2.5 px-3 text-right font-mono tabular-nums text-slate-100">
                {{ formatIskFull(opportunity.profitPerUnit) }}
                <div v-if="opportunity.marginPercent !== null" class="text-xs text-slate-500">{{ formatNumber(opportunity.marginPercent, MARGIN_DECIMALS) }} %</div>
              </td>
              <td data-testid="opportunity-quantity" class="py-2.5 px-3 text-right font-mono tabular-nums text-slate-100">
                <SellMarketUnknownValue v-if="opportunity.missingSkills.length > 0" :cause="t('industry.opportunities.required', { skills: requiredSkills(opportunity) })" />
                <SellMarketUnknownValue v-else-if="opportunity.weeklyQuantity === null" :cause="t('industry.opportunities.unknown.slots')" />
                <template v-else>
                  <b>{{ formatNumber(opportunity.weeklyQuantity, 0) }}</b>
                  <div v-if="opportunity.limitedBy !== null" data-testid="opportunity-limit" class="text-xs font-sans text-amber-400">
                    {{ t(`industry.opportunities.limitedBy.${opportunity.limitedBy}`) }}
                  </div>
                </template>
              </td>
              <td data-testid="opportunity-profit" class="py-2.5 px-3 text-right font-mono tabular-nums text-emerald-400">
                <span v-if="opportunity.weeklyProfit === null" class="text-slate-500">—</span>
                <template v-else>
                  {{ formatIskFull(opportunity.weeklyProfit) }}
                  <div v-if="opportunity.iskPerSlotHour !== null" data-testid="opportunity-isk-hour" class="text-xs text-slate-500">
                    {{ t('industry.opportunities.perHour', { amount: formatIskFull(opportunity.iskPerSlotHour) }) }}
                  </div>
                </template>
              </td>
              <td class="py-2.5 px-4 text-xs">
                <span v-if="opportunity.groupHint" data-testid="opportunity-group-hint" class="text-violet-300">
                  {{ t('industry.opportunities.group') }}
                  <span class="block text-slate-500">{{ opportunity.missingSkills.length > 0 ? t('industry.opportunities.groupWithSkill') : t('industry.opportunities.groupRaiseCap') }}</span>
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </DataTable>

      <p class="px-4 py-3 text-xs text-slate-500">{{ t('industry.opportunities.footnote') }}</p>
    </template>
  </section>
</template>
