<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MiningOperation, MiningOperationValuation, MiningPayment } from '@/stores/miningOperation'
import { formatWhole } from '@/views/MiningOperation/miningOperationQuantity'
import { formatEveClock, formatEveDateTime } from '@/views/MiningOperation/miningOperationTime'

interface RecapRow {
  label: string
  value: string
  unknown?: boolean
}

const props = defineProps<{
  operation: MiningOperation
  valuation: MiningOperationValuation
  payments: MiningPayment[]
  marketLabel: string
}>()

const { t, locale } = useI18n()

const settledByEachForThemselves = computed(() => props.operation.status === 'completed')

function cutRow(label: string, prefix: string, percent: number, amount: number | null): RecapRow {
  const amountText = amount === null ? t('miningOperation.quantities.unknown') : formatWhole(amount, locale.value)
  return { label, value: `${prefix}${percent} % (${amountText})`, unknown: amount === null }
}

function amountRow(label: string, amount: number | null, unit = ''): RecapRow {
  return amount === null
    ? { label, value: t('miningOperation.quantities.unknown'), unknown: true }
    : { label, value: `${formatWhole(amount, locale.value)}${unit}` }
}

const rows = computed<RecapRow[]>(() => {
  const operation = props.operation
  const valuation = props.valuation
  const settings = valuation.settings
  const period = operation.startedAt !== null && operation.endedAt !== null
    ? `${formatEveDateTime(operation.startedAt)} → ${formatEveClock(operation.endedAt)} EVE`
    : null
  const basis = t('miningOperation.valuation.basis', {
    form: t(`miningOperation.valuation.form.${settings.valuationForm}`),
    market: props.marketLabel,
    side: t(`miningOperation.valuation.side.${settings.valuationSide}`),
  })
  const archiver = operation.archivedAt === null
    ? null
    : t(settledByEachForThemselves.value ? 'miningOperation.recap.completedBy' : 'miningOperation.recap.settledBy', {
      date: formatEveDateTime(operation.archivedAt),
      name: operation.archivedByName ?? t('miningOperation.unknownPilot'),
    })

  const recap: RecapRow[] = [
    period === null ? { label: t('miningOperation.recap.period'), value: t('miningOperation.quantities.unknown'), unknown: true } : { label: t('miningOperation.recap.period'), value: period },
    { label: t('miningOperation.recap.system'), value: operation.solarSystemName ?? t('miningOperation.start.systemNone') },
    { label: t('miningOperation.recap.participants'), value: t('miningOperation.recap.participantsCount', { participants: operation.participantsCount, characters: operation.charactersCount }) },
    amountRow(t('miningOperation.recap.totalValue'), valuation.totalValue, ' ISK'),
    amountRow(t('miningOperation.recap.iskPerHour'), valuation.iskPerHour === null ? null : Math.round(valuation.iskPerHour), ' ISK/h'),
    { label: t('miningOperation.recap.basis'), value: basis },
  ]
  if (!settledByEachForThemselves.value) {
    if (settings.boosterCutPercent > 0) {
      const name = settings.boosterCharacterName === null ? '' : `${settings.boosterCharacterName} · `
      recap.push(cutRow(t('miningOperation.recap.booster'), name, settings.boosterCutPercent, valuation.boosterCut))
    }
    if (settings.corporationCutPercent > 0) {
      recap.push(cutRow(t('miningOperation.recap.corporation'), '', settings.corporationCutPercent, valuation.corporationCut))
    }
    recap.push({ label: t('miningOperation.recap.payments'), value: t('miningOperation.payments.count', { paid: props.payments.filter(payment => payment.paid).length, total: props.payments.length }) })
  }
  if (archiver !== null) recap.push({ label: t(`miningOperation.status.${operation.status}`), value: archiver })
  return recap
})
</script>

<template>
  <section class="eve-card p-5">
    <h2 class="mb-3 font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.recap.title') }}</h2>
    <dl class="space-y-1.5 text-sm">
      <div v-for="row in rows" :key="row.label" class="flex flex-wrap items-baseline justify-between gap-x-3">
        <dt class="text-slate-400">{{ row.label }}</dt>
        <dd class="min-w-0 truncate font-mono" :class="row.unknown ? 'text-amber-300' : 'text-slate-100'" :title="row.value">{{ row.value }}</dd>
      </div>
    </dl>
  </section>
</template>
