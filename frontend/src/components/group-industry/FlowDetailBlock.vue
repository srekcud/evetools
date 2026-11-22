<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { DETAIL_BLOCK_ORIGINS, DETAIL_BLOCK_TABS, type DetailBlockKind } from './projectFlowCardDetail'

defineProps<{
  kind: DetailBlockKind
  loading: boolean
  error: string | null
}>()

const { t } = useI18n()
</script>

<template>
  <section :data-testid="`flow-detail-block-${kind}`" class="border-t border-slate-800 px-3 py-2 text-xs text-slate-300">
    <header class="mb-1.5 flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
      <h3 class="font-display text-[12.5px] font-bold uppercase tracking-[0.08em] text-slate-200">{{ t(`groupIndustry.flow.detail.titles.${kind}`) }}</h3>
      <span data-testid="flow-detail-origin" class="text-[10.5px] text-slate-500">{{ t(DETAIL_BLOCK_ORIGINS[kind]) }}</span>
      <router-link
        :to="{ query: { tab: DETAIL_BLOCK_TABS[kind] } }"
        data-testid="flow-detail-open-tab"
        class="ml-auto text-[10.5px] text-cyan-400 hover:underline"
      >{{ t('groupIndustry.flow.detail.openTab') }}</router-link>
    </header>
    <p v-if="error !== null" role="alert" class="text-red-300">{{ error }}</p>
    <p v-else-if="loading" class="text-slate-400">{{ t('groupIndustry.flow.detail.loading') }}</p>
    <slot v-else />
  </section>
</template>
