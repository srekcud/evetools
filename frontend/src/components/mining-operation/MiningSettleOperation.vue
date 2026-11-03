<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MiningPayment } from '@/stores/miningOperation'

const props = defineProps<{
  payments: MiningPayment[]
}>()

const emit = defineEmits<{
  settle: []
}>()

const { t } = useI18n()

const unpaidCount = computed(() => props.payments.filter(payment => !payment.paid).length)
</script>

<template>
  <section class="eve-card p-5">
    <h2 class="mb-2 font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.settle.title') }}</h2>
    <p class="mb-3 text-sm text-slate-300">{{ t('miningOperation.settle.hint') }}</p>
    <p v-if="unpaidCount > 0" class="mb-3 rounded-sm border border-amber-500/30 bg-amber-500/5 px-3 py-1.5 text-xs text-amber-200">{{ t('miningOperation.settle.left', { count: unpaidCount }) }}</p>
    <button
      type="button"
      class="rounded-md bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-50"
      :disabled="unpaidCount > 0"
      @click="emit('settle')"
    >{{ t('miningOperation.settle.action') }}</button>
  </section>
</template>
