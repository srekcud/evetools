<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  complete: boolean
  collapsed: boolean
  materialCount: number
}>()

defineEmits<{
  toggle: []
  openChain: []
}>()

const { t } = useI18n()
</script>

<template>
  <div
    v-if="complete"
    data-testid="sourcing-summary"
    class="mb-6 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl border border-emerald-500/25 bg-emerald-500/5 px-4 py-3"
  >
    <p class="text-sm text-slate-300">
      <span class="font-medium text-emerald-400">{{ t('groupIndustry.sourcingDone.summary', { count: materialCount }, materialCount) }}</span>
      {{ t('groupIndustry.sourcingDone.pointer') }}
    </p>
    <div class="flex flex-wrap gap-2">
      <button
        type="button"
        data-testid="sourcing-open-chain"
        class="rounded-lg bg-cyan-600 px-3 py-1.5 text-sm font-medium text-white transition-all hover:-translate-y-px hover:bg-cyan-500"
        @click="$emit('openChain')"
      >
        {{ t('groupIndustry.sourcingDone.openChain') }}
      </button>
      <button
        type="button"
        data-testid="sourcing-toggle"
        :aria-expanded="!collapsed"
        class="rounded-lg bg-slate-700 px-3 py-1.5 text-sm text-slate-200 transition-all hover:-translate-y-px hover:bg-slate-600"
        @click="$emit('toggle')"
      >
        {{ collapsed ? t('groupIndustry.sourcingDone.show') : t('groupIndustry.sourcingDone.hide') }}
      </button>
    </div>
  </div>
  <slot v-if="!collapsed" />
</template>
