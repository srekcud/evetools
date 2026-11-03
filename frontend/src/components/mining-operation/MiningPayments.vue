<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MiningPayment } from '@/stores/miningOperation'
import { formatWhole } from '@/views/MiningOperation/miningOperationQuantity'
import { formatEveClock, formatEveDate } from '@/views/MiningOperation/miningOperationTime'

const props = defineProps<{
  payments: MiningPayment[]
  corporationCutPercent: number
  boosterCutPercent: number
  canAdminister: boolean
  ownMemberId: string | null
  readOnly: boolean
}>()

const emit = defineEmits<{
  mark: [paymentId: string, paid: boolean]
}>()

const { t, locale } = useI18n()

const paidCount = computed(() => props.payments.filter(payment => payment.paid).length)
const ruleText = computed(() => t(props.boosterCutPercent > 0 ? 'miningOperation.payments.rule' : 'miningOperation.payments.ruleCorporationOnly'))

function payerOf(payment: MiningPayment): string {
  return payment.payerName ?? t('miningOperation.unknownPilot')
}

function beneficiaryOf(payment: MiningPayment): string {
  return payment.beneficiaryName ?? t('miningOperation.payments.corporation')
}

function amountOf(payment: MiningPayment): string {
  return formatWhole(payment.amount, locale.value)
}

function canTick(payment: MiningPayment): boolean {
  if (props.readOnly) return false
  return props.canAdminister || (payment.payerMemberId !== null && payment.payerMemberId === props.ownMemberId)
}

function markLabelOf(payment: MiningPayment): string {
  return t('miningOperation.payments.markLabel', { payer: payerOf(payment), amount: amountOf(payment), beneficiary: beneficiaryOf(payment) })
}

function reasonOf(payment: MiningPayment): string {
  return t(`miningOperation.payments.kind.${payment.kind}`, { percent: props.corporationCutPercent })
}

function traceOf(payment: MiningPayment): string {
  if (!payment.paid || payment.paidAt === null) return t('miningOperation.payments.pendingTrace')
  return t('miningOperation.payments.paidTrace', {
    name: payment.paidByName ?? t('miningOperation.unknownPilot'),
    date: formatEveDate(payment.paidAt),
    time: formatEveClock(payment.paidAt),
  })
}

function onTick(payment: MiningPayment, event: Event): void {
  emit('mark', payment.id, (event.target as HTMLInputElement).checked)
}
</script>

<template>
  <section class="eve-card p-5">
    <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
      <h2 class="font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.payments.title') }}</h2>
      <p class="font-mono text-xs text-slate-400">{{ t('miningOperation.payments.count', { paid: paidCount, total: payments.length }) }}</p>
    </div>
    <p class="mb-3 text-sm text-slate-300">{{ ruleText }}</p>
    <p v-if="payments.length === 0" class="text-sm text-slate-400">{{ t('miningOperation.archive.nothingToSettle') }}</p>
    <ul v-else class="divide-y divide-slate-800/60">
      <li v-for="payment in payments" :key="payment.id" class="flex items-start gap-3 py-3">
        <input
          type="checkbox"
          class="mt-1 h-4 w-4 shrink-0 accent-cyan-500 disabled:cursor-not-allowed disabled:opacity-50"
          :checked="payment.paid"
          :disabled="!canTick(payment)"
          :aria-label="markLabelOf(payment)"
          @change="onTick(payment, $event)"
        />
        <div class="min-w-0 flex-1">
          <i18n-t keypath="miningOperation.payments.line" scope="global" tag="p" class="flex min-w-0 flex-wrap items-baseline gap-x-1 text-sm text-slate-100">
            <template #payer><span class="max-w-56 truncate font-medium" :title="payerOf(payment)">{{ payerOf(payment) }}</span></template>
            <template #amount><span class="font-mono text-cyan-200">{{ amountOf(payment) }}</span></template>
            <template #beneficiary><span class="max-w-56 truncate font-medium" :title="beneficiaryOf(payment)">{{ beneficiaryOf(payment) }}</span></template>
          </i18n-t>
          <p class="mt-0.5 text-xs text-slate-400">{{ reasonOf(payment) }}</p>
          <p class="mt-0.5 font-mono text-xs" :class="payment.paid ? 'text-emerald-300' : 'text-slate-500'">{{ traceOf(payment) }}</p>
        </div>
        <span
          class="shrink-0 rounded-sm border px-2 py-0.5 font-mono text-xs uppercase tracking-wider"
          :class="payment.paid ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300' : 'border-amber-500/40 bg-amber-500/10 text-amber-300'"
        >{{ t(payment.paid ? 'miningOperation.payments.paid' : 'miningOperation.payments.pending') }}</span>
      </li>
    </ul>
  </section>
</template>
