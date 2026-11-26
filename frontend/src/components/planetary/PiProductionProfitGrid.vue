<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEveImages } from '@/composables/useEveImages'
import { formatIskShort } from '@/composables/useIskParser'
import type { PlanetaryProductionProfit, ProductionProfitLine } from '@/stores/planetary'

const ICON_SIZE = 32
const UNKNOWN_HATCH = 'bg-[repeating-linear-gradient(45deg,transparent_0_6px,rgba(100,116,139,0.13)_6px_12px)]'

const props = defineProps<{ profit: PlanetaryProductionProfit }>()

const { t } = useI18n()
const { getTypeIconUrl, onImageError } = useEveImages()

const hasExtractedInput = computed(() => props.profit.lines.some(line => line.valuesExtractedAtHubBuy))

function money(value: number): string {
  return value < 0 ? `-${formatIskShort(-value)}` : formatIskShort(value)
}

function optionalMoney(value: number | null): string {
  return value === null ? t('pi.revenue.profit.unknown') : money(value)
}

function profitState(line: ProductionProfitLine): 'internal' | 'unknown' | 'known' {
  if (line.internal) return 'internal'
  return line.profitPerDay === null ? 'unknown' : 'known'
}

function stateOf(value: number | null): 'unknown' | 'known' {
  return value === null ? 'unknown' : 'known'
}

function profitTone(value: number | null): string {
  if (value === null) return 'text-slate-500'
  return value < 0 ? 'text-red-400' : 'text-emerald-400'
}
</script>

<template>
  <section class="rounded-xl border border-slate-700/60 bg-slate-900/60" data-testid="production-profit-grid">
    <header class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-700/60 px-4 py-3">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-cyan-300">{{ t('pi.revenue.profit.title') }}</h3>
      <p class="text-xs text-slate-400" data-testid="profit-hub">{{ t('pi.revenue.profit.hub', { hub: profit.hub.structureName }) }}</p>
    </header>

    <p v-if="!profit.hub.hasData" class="border-b border-slate-700/60 px-4 py-2 text-xs text-amber-300" data-testid="profit-no-hub-data">
      {{ t('pi.revenue.profit.noHubData') }}
    </p>

    <p v-if="profit.lines.length === 0" class="px-4 py-6 text-sm text-slate-400" data-testid="profit-empty">{{ t('pi.revenue.profit.empty') }}</p>

    <div v-else class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wide text-slate-400">
            <th scope="col" class="px-4 py-2 font-medium">{{ t('pi.revenue.profit.product') }}</th>
            <th scope="col" class="px-3 py-2 text-right font-medium">{{ t('pi.revenue.profit.sold') }}</th>
            <th scope="col" class="px-3 py-2 text-right font-medium">{{ t('pi.revenue.profit.sales') }}</th>
            <th scope="col" class="px-3 py-2 text-right font-medium">{{ t('pi.revenue.profit.inputs') }}</th>
            <th scope="col" class="px-3 py-2 text-right font-medium">{{ t('pi.revenue.profit.profit') }}</th>
            <th scope="col" class="px-3 py-2 text-right font-medium">{{ t('pi.revenue.profit.margin') }}</th>
            <th scope="col" class="px-4 py-2 text-right font-medium">{{ t('pi.revenue.profit.jita') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="line in profit.lines"
            :key="line.typeId"
            class="border-t border-slate-800"
            data-testid="profit-line"
            :data-type-id="line.typeId"
          >
            <td class="px-4 py-2">
              <span class="flex items-center gap-2 text-slate-100">
                <img :src="getTypeIconUrl(line.typeId, ICON_SIZE)" alt="" class="h-6 w-6 shrink-0 rounded-sm" @error="onImageError" />
                <span class="truncate">{{ line.typeName ?? line.typeId }}</span>
                <span class="rounded bg-slate-800 px-1.5 text-xs text-slate-300">{{ line.tier }}</span>
              </span>
            </td>
            <td class="px-3 py-2 text-right font-mono text-slate-300" data-cell="sold">{{ formatIskShort(line.soldQuantity) }}</td>
            <td
              class="px-3 py-2 text-right font-mono text-slate-300"
              :class="line.salesIsk === null ? UNKNOWN_HATCH : ''"
              data-cell="sales"
              :data-state="stateOf(line.salesIsk)"
            >{{ optionalMoney(line.salesIsk) }}</td>
            <template v-if="line.internal">
              <td class="px-3 py-2 text-right font-mono text-slate-500" data-cell="inputs" data-state="internal">—</td>
              <td
                class="px-3 py-2 text-right text-xs text-slate-400"
                colspan="2"
                data-cell="profit"
                data-state="internal"
              >{{ t('pi.revenue.profit.internal') }}</td>
            </template>
            <template v-else>
              <td
                class="px-3 py-2 text-right font-mono text-slate-300"
                :class="line.inputsIsk === null ? UNKNOWN_HATCH : ''"
                data-cell="inputs"
                :data-state="stateOf(line.inputsIsk)"
              >{{ optionalMoney(line.inputsIsk) }}</td>
              <td
                class="px-3 py-2 text-right font-mono font-semibold"
                :class="[profitTone(line.profitPerDay), profitState(line) === 'unknown' ? UNKNOWN_HATCH : '']"
                data-cell="profit"
                :data-state="profitState(line)"
              >{{ optionalMoney(line.profitPerDay) }}</td>
              <td
                class="px-3 py-2 text-right font-mono text-slate-300"
                :class="line.marginPercent === null ? UNKNOWN_HATCH : ''"
                data-cell="margin"
                :data-state="stateOf(line.marginPercent)"
              >{{ line.marginPercent === null ? t('pi.revenue.profit.unknown') : `${Math.round(line.marginPercent)} %` }}</td>
            </template>
            <td class="px-4 py-2 text-right font-mono text-slate-400" data-cell="jita">{{ line.jitaIsk === null ? '—' : money(line.jitaIsk) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <footer class="space-y-1 border-t border-slate-700/60 px-4 py-3 text-sm">
      <dl class="grid grid-cols-[1fr_auto] gap-x-6 gap-y-1">
        <dt class="text-slate-300">{{ t('pi.revenue.profit.totalKnown') }}</dt>
        <dd class="text-right font-mono font-semibold" :class="profitTone(profit.profitPerDay)" data-testid="profit-total">{{ money(profit.profitPerDay) }}</dd>
        <dt class="text-slate-300">{{ t('pi.revenue.profit.taxes', { n: profit.taxes.days }) }}</dt>
        <dd
          class="text-right font-mono text-slate-300"
          :class="profit.taxes.perDay === null ? UNKNOWN_HATCH : ''"
          data-testid="profit-taxes"
          :data-state="stateOf(profit.taxes.perDay)"
        >{{ optionalMoney(profit.taxes.perDay) }}</dd>
        <dt class="text-slate-100">{{ t('pi.revenue.profit.net') }}</dt>
        <dd class="text-right font-mono font-semibold" :class="profitTone(profit.netProfitPerDay)" data-testid="profit-net">{{ money(profit.netProfitPerDay) }}</dd>
      </dl>
      <p v-if="profit.unknownLineCount > 0" class="text-xs text-slate-400" data-testid="profit-unknown-count">
        {{ t('pi.revenue.profit.unknownCount', { n: profit.unknownLineCount }, profit.unknownLineCount) }}
      </p>
      <p v-if="hasExtractedInput" class="text-xs text-slate-400" data-testid="profit-extracted-note">{{ t('pi.revenue.profit.extractedNote') }}</p>
    </footer>
  </section>
</template>
