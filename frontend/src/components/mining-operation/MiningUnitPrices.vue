<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { MiningUnitPrice } from '@/stores/miningOperation'
import { formatUnitPrice } from '@/views/MiningOperation/miningOperationQuantity'

defineProps<{
  title: string
  unitPrices: MiningUnitPrice[]
}>()

const { t, locale } = useI18n()
</script>

<template>
  <section class="eve-card self-start p-5">
    <h2 class="mb-2 font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ title }}</h2>
    <ul class="space-y-1 text-sm">
      <li v-for="unitPrice in unitPrices" :key="unitPrice.typeId" class="flex items-center justify-between gap-2">
        <span class="min-w-0 truncate text-slate-300" :title="unitPrice.typeName ?? String(unitPrice.typeId)">{{ unitPrice.typeName ?? unitPrice.typeId }}</span>
        <span v-if="unitPrice.unitPrice === null" class="font-mono text-amber-300">{{ t('miningOperation.shares.noPrice') }}</span>
        <span v-else class="font-mono text-slate-100">{{ formatUnitPrice(unitPrice.unitPrice, locale) }}</span>
      </li>
    </ul>
  </section>
</template>
