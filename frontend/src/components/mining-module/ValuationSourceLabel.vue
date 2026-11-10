<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ValuationSource } from '@/stores/miningValuationProfile'

defineProps<{
  source: ValuationSource | null
}>()

const SEPARATOR = ' · '

const { t } = useI18n()
</script>

<template>
  <span v-if="source === null" class="text-xs text-slate-500">{{ t('miningModule.valuationSource.unknown') }}</span>
  <span v-else class="inline-flex max-w-full items-center gap-1 text-xs text-slate-400">
    <span
      v-if="source.market === 'structure' && source.structureName !== null"
      class="truncate max-w-48"
      :title="source.structureName"
    >{{ source.structureName }}</span>
    <span v-else>{{ t(`miningOperation.valuation.market.${source.market}`) }}</span>
    <span aria-hidden="true">{{ SEPARATOR }}</span>
    <span>{{ t(`miningOperation.valuation.side.${source.side}`) }}</span>
    <span aria-hidden="true">{{ SEPARATOR }}</span>
    <span>{{ t(`miningOperation.valuation.form.${source.form}`) }}</span>
  </span>
</template>
