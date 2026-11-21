<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import type { PurchaseSuggestion } from '@/stores/group-industry/types'

defineProps<{
  transactions: PurchaseSuggestion[]
  isLinking: boolean
  linkError: string | null
}>()

const emit = defineEmits<{
  link: [suggestion: PurchaseSuggestion]
}>()

const { t } = useI18n()
const { formatIsk, formatDateTime, formatNumber } = useFormatters()
</script>

<template>
  <section data-testid="wallet-first" class="rounded-lg border border-cyan-500/30 bg-cyan-500/5 p-4 space-y-2">
    <p class="text-xs font-medium uppercase tracking-wider text-cyan-400">{{ t('groupIndustry.contribute.walletFirst.title') }}</p>
    <ul class="space-y-1.5">
      <li
        v-for="suggestion in transactions"
        :key="suggestion.transactionId"
        data-testid="wallet-first-transaction"
        class="flex items-center gap-3 text-xs text-slate-300"
      >
        <span class="text-slate-500">{{ formatDateTime(suggestion.date) }}</span>
        <span>{{ suggestion.characterName }}</span>
        <span class="ml-auto font-mono" style="font-variant-numeric: tabular-nums;">{{ formatNumber(suggestion.quantity, 0) }} &middot; {{ formatIsk(suggestion.totalPrice, 1) }}</span>
        <button
          type="button"
          data-testid="wallet-first-link"
          class="px-2 py-0.5 rounded-sm text-xs bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 hover:bg-cyan-500/20 disabled:opacity-50"
          :disabled="isLinking"
          @click="emit('link', suggestion)"
        >{{ t('groupIndustry.contribute.walletFirst.link') }}</button>
      </li>
    </ul>
    <p v-if="linkError !== null" role="alert" class="text-xs text-red-400">{{ linkError }}</p>
  </section>
</template>
