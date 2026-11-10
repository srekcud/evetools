<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { chunkDay } from '@/composables/useCorporationMiningExtractions'
import { useFormatters } from '@/composables/useFormatters'
import type { ChunkMiner, ChunkMinerStatement, ChunkMinersState } from '@/composables/useMoonChunkMiners'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import MoonTaxAmountCell from './moon-tax/MoonTaxAmountCell.vue'
import MoonTaxStatusCell from './moon-tax/MoonTaxStatusCell.vue'

const EVE_TIME_ZONE = 'UTC'
const MS_PER_DAY = 86_400_000
const WEEK_DAYS = 7
const COLUMNS = ['period', 'reference', 'tax', 'paid', 'remaining', 'status']
const NO_UNKNOWN_TYPES: number[] = []

defineProps<{
  state: ChunkMinersState
}>()

const { t, locale } = useI18n()
const { formatIsk } = useFormatters()

function minerLabel(miner: ChunkMiner): string {
  return miner.characterName ?? `#${miner.characterId}`
}

function periodLabel(statement: ChunkMinerStatement): string {
  const days = (new Date(statement.periodEnd).getTime() - new Date(statement.periodStart).getTime()) / MS_PER_DAY
  if (days === WEEK_DAYS) {
    return t('ledger.mining.moonChunks.miners.week', { date: chunkDay(statement.periodStart, EVE_TIME_ZONE) })
  }
  const month = new Intl.DateTimeFormat(locale.value, { month: 'long', year: 'numeric', timeZone: EVE_TIME_ZONE }).format(new Date(statement.periodStart))
  return t('ledger.mining.moonChunks.miners.month', { month })
}
</script>

<template>
  <div v-if="state.kind === 'loading'" class="flex justify-center py-6">
    <LoadingSpinner size="lg" class="text-cyan-500" />
  </div>

  <p v-else-if="state.kind === 'denied'" data-miners-denied role="status" class="rounded-sm border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-300">
    <span aria-hidden="true">⚠ </span>{{ t('ledger.mining.moonChunks.miners.denied') }}
  </p>

  <ErrorBanner v-else-if="state.kind === 'error'" data-miners-error :message="t('ledger.mining.moonChunks.miners.loadError')" />

  <div v-else data-miners class="space-y-3 rounded-sm border border-slate-800 bg-slate-900/60 p-4">
    <div>
      <h5 class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ t('ledger.mining.moonChunks.miners.title') }}</h5>
      <p class="text-xs text-slate-500">{{ t('ledger.mining.moonChunks.miners.periodNote') }}</p>
    </div>

    <p v-if="!state.payload.refineryOpen" class="text-sm text-amber-300">
      <span aria-hidden="true">⚠ </span>{{ t('ledger.mining.moonChunks.miners.notConfigured') }}
    </p>
    <p v-else-if="state.payload.miners.length === 0" class="text-sm text-slate-500">
      {{ t('ledger.mining.moonChunks.miners.empty') }}
    </p>

    <ul v-else class="space-y-3">
      <li
        v-for="miner in state.payload.miners"
        :key="miner.characterId"
        data-miner-row
        :data-character-id="miner.characterId"
        class="space-y-2 rounded-sm border border-slate-800 bg-slate-950/40 p-3"
      >
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
          <span class="block max-w-[20rem] truncate text-sm font-semibold text-white" :title="minerLabel(miner)">{{ minerLabel(miner) }}</span>
          <span data-chunk-tax class="text-sm">
            <span class="text-xs uppercase tracking-wider text-slate-400">{{ t('ledger.mining.moonChunks.miners.chunkTax') }}</span>
            <span v-if="miner.chunkTax !== null" class="ml-2 text-slate-200">{{ formatIsk(miner.chunkTax) }}</span>
            <span v-else class="ml-2 text-amber-400">
              <span aria-hidden="true">⚠ </span>{{ t('ledger.mining.moonTax.unknown') }}
              <span v-if="miner.unknownTypeIds.length > 0" class="block text-xs text-amber-500/80">
                {{ t('ledger.mining.moonTax.withoutPrice', { ids: miner.unknownTypeIds.join(', ') }) }}
              </span>
            </span>
          </span>
        </div>

        <div v-if="miner.statements.length > 0" class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr>
                <th
                  v-for="column in COLUMNS"
                  :key="column"
                  scope="col"
                  class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider whitespace-nowrap text-slate-400"
                >
                  {{ t(`ledger.mining.moonChunks.miners.columns.${column}`) }}
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800">
              <tr
                v-for="statement in miner.statements"
                :key="statement.periodStart"
                data-miner-statement
                :data-period-start="statement.periodStart"
              >
                <td class="px-3 py-2 text-sm whitespace-nowrap text-slate-300">{{ periodLabel(statement) }}</td>
                <template v-if="statement.status !== null">
                  <td class="px-3 py-2 font-mono text-xs whitespace-nowrap text-slate-300">{{ statement.reference ?? '—' }}</td>
                  <MoonTaxAmountCell :amount="statement.tax" :unknown-type-ids="NO_UNKNOWN_TYPES" />
                  <MoonTaxAmountCell :amount="statement.paid" :unknown-type-ids="NO_UNKNOWN_TYPES" />
                  <MoonTaxAmountCell :amount="statement.remaining" :unknown-type-ids="NO_UNKNOWN_TYPES" />
                  <MoonTaxStatusCell :status="statement.status" />
                </template>
                <td v-else :colspan="COLUMNS.length - 1" class="px-3 py-2 text-sm text-amber-400">
                  <span aria-hidden="true">⚠ </span>{{ t('ledger.mining.moonChunks.miners.notEstablished') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </li>
    </ul>
  </div>
</template>
