<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { summarizeStatements, type MoonTaxStatement, type MoonTaxSum } from '@/composables/useMoonTax'

const props = defineProps<{
  statements: MoonTaxStatement[]
}>()

interface TotalCard {
  key: string
  display: string
  isUnknown: boolean
  knownPart: string | null
}

const { t } = useI18n()
const { formatIsk } = useFormatters()

function amountCard(key: string, sum: MoonTaxSum): TotalCard {
  return {
    key,
    display: sum.isComplete ? formatIsk(sum.knownTotal) : t('ledger.mining.moonTax.totals.unknown'),
    isUnknown: !sum.isComplete,
    knownPart: sum.isComplete ? null : t('ledger.mining.moonTax.totals.knownPart', { amount: formatIsk(sum.knownTotal) }),
  }
}

const cards = computed<TotalCard[]>(() => {
  const totals = summarizeStatements(props.statements)
  return [
    amountCard('due', totals.due),
    amountCard('paid', totals.paid),
    amountCard('remaining', totals.remaining),
    { key: 'outside', display: String(totals.outsideAllianceCount), isUnknown: false, knownPart: null },
  ]
})
</script>

<template>
  <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div
      v-for="card in cards"
      :key="card.key"
      :data-total="card.key"
      :data-unknown="card.isUnknown ? '' : undefined"
      class="rounded-xl border border-slate-800 bg-slate-900 p-4"
    >
      <span class="block text-xs font-medium uppercase tracking-wider text-slate-400">{{ t(`ledger.mining.moonTax.totals.${card.key}`) }}</span>
      <p :class="['mt-1 text-lg font-semibold', card.isUnknown ? 'text-amber-400' : 'text-white']">
        <span v-if="card.isUnknown" aria-hidden="true">⚠ </span>{{ card.display }}
      </p>
      <p v-if="card.knownPart !== null" data-known-part class="text-xs text-slate-400">{{ card.knownPart }}</p>
    </div>
  </div>
</template>
