<script setup lang="ts">
import { watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupSurplusStore } from '@/stores/group-industry/surplus'

const props = defineProps<{
  projectId: string
}>()

const { t } = useI18n()
const { formatIskFull, formatNumber } = useFormatters()
const surplusStore = useGroupSurplusStore()

function priceText(price: number | null): string {
  return price === null ? t('groupIndustry.surplus.unknown') : formatNumber(price)
}

function valueText(value: number | null): string {
  return value === null ? t('groupIndustry.surplus.unknown') : formatIskFull(value)
}

watch(() => props.projectId, projectId => surplusStore.fetchSurplus(projectId), { immediate: true })
</script>

<template>
  <section data-testid="project-surplus" class="bg-slate-900/80 rounded-xl border border-slate-800 overflow-hidden">
    <header class="px-4 py-3 border-b border-slate-800">
      <h2 class="font-display text-sm font-semibold uppercase tracking-widest text-cyan-400">
        {{ t('groupIndustry.surplus.title') }}
      </h2>
    </header>

    <p
      v-if="surplusStore.loading && surplusStore.lines.length === 0"
      data-testid="project-surplus-loading"
      class="p-6 text-center text-slate-500"
    >{{ t('groupIndustry.surplus.loading') }}</p>

    <div
      v-else-if="surplusStore.error !== null"
      data-testid="project-surplus-error"
      role="alert"
      class="m-4 p-3 rounded border border-red-500/30 bg-red-500/10 text-sm text-red-300"
    >
      <p class="font-semibold">{{ t('groupIndustry.surplus.loadError') }}</p>
      <p class="mt-1 break-words">{{ surplusStore.error }}</p>
    </div>

    <p
      v-else-if="surplusStore.lines.length === 0"
      data-testid="project-surplus-empty"
      class="p-6 text-center text-slate-500"
    >{{ t('groupIndustry.surplus.empty') }}</p>

    <div v-else class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
            <th class="px-4 py-2 font-medium">{{ t('groupIndustry.surplus.columns.type') }}</th>
            <th class="px-4 py-2 font-medium text-right">{{ t('groupIndustry.surplus.columns.required') }}</th>
            <th class="px-4 py-2 font-medium text-right">{{ t('groupIndustry.surplus.columns.contributed') }}</th>
            <th class="px-4 py-2 font-medium text-right">{{ t('groupIndustry.surplus.columns.surplus') }}</th>
            <th class="px-4 py-2 font-medium text-right">{{ t('groupIndustry.surplus.columns.unitPrice') }}</th>
            <th class="px-4 py-2 font-medium text-right">{{ t('groupIndustry.surplus.columns.value') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/60">
          <tr
            v-for="line in surplusStore.lines"
            :key="line.lineId"
            :data-testid="`project-surplus-row-${line.lineId}`"
          >
            <td class="px-4 py-2 font-medium text-slate-100 max-w-[18rem] truncate" :title="line.typeName">{{ line.typeName }}</td>
            <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ formatNumber(line.requiredQuantity, 0) }}</td>
            <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ formatNumber(line.contributedQuantity, 0) }}</td>
            <td class="px-4 py-2 text-right tabular-nums text-amber-300">{{ formatNumber(line.surplusQuantity, 0) }}</td>
            <td class="px-4 py-2 text-right tabular-nums text-slate-400">{{ priceText(line.unitPrice) }}</td>
            <td class="px-4 py-2 text-right tabular-nums text-slate-100">{{ valueText(line.surplusValue) }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr data-testid="project-surplus-total" class="border-t border-slate-700">
            <td class="px-4 py-2 font-semibold text-slate-300" colspan="5">{{ t('groupIndustry.surplus.total') }}</td>
            <td class="px-4 py-2 text-right tabular-nums font-semibold text-slate-100">{{ valueText(surplusStore.totalValue) }}</td>
          </tr>
        </tfoot>
      </table>
    </div>

    <p
      data-testid="project-surplus-readonly"
      class="px-4 py-3 border-t border-slate-800 text-xs text-slate-500"
    >{{ t('groupIndustry.surplus.readOnly') }}</p>
  </section>
</template>
