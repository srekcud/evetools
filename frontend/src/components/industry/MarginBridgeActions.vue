<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useTypeNames } from '@/composables/useTypeNames'
import { useIndustryStore } from '@/stores/industry'
import type { ProfitMarginResult } from '@/stores/industry/types'

const props = defineProps<{
  result: ProfitMarginResult
  meLevel: number
}>()

const { t } = useI18n()
const industryStore = useIndustryStore()
const { typeNameLabel } = useTypeNames()

function createProject(): void {
  industryStore.navigationIntent = {
    target: 'projects',
    prefill: {
      typeId: props.result.typeId,
      typeName: typeNameLabel(props.result.typeName, props.result.typeId),
      runs: props.result.runs,
      me: props.meLevel,
      isT2: props.result.isT2,
    },
  }
}

function openBuyVsBuild(): void {
  industryStore.navigationIntent = { target: 'buy-vs-build', typeId: props.result.typeId }
}
</script>

<template>
  <div data-testid="margin-bridge-actions" class="flex flex-wrap items-center gap-3">
    <button
      type="button"
      data-testid="margin-create-project"
      class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 rounded-lg text-white text-sm font-medium flex items-center gap-2 transition-colors"
      @click="createProject"
    >
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
      </svg>
      {{ t('industry.bridges.createProject') }}
    </button>
    <button
      type="button"
      data-testid="margin-buy-vs-build"
      class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-slate-200 text-sm font-medium transition-colors"
      @click="openBuyVsBuild"
    >
      {{ t('industry.bridges.buyVsBuild') }}
    </button>
    <RouterLink
      data-testid="margin-market-link"
      :to="`/market?tab=detail&typeId=${result.typeId}`"
      class="px-4 py-2 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg text-cyan-300 text-sm font-medium transition-colors"
    >
      {{ t('industry.bridges.viewMarket') }}
    </RouterLink>
  </div>
</template>
