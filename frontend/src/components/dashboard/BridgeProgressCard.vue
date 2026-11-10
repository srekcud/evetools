<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import type { BridgeCardStatus } from '@/composables/bridgeCardStatus'

defineProps<{
  cardId: string
  title: string
  status: BridgeCardStatus
  emptyLabel: string
  linkTo: string
  linkLabel: string
}>()

const { t } = useI18n()
</script>

<template>
  <section
    :data-testid="`dashboard-progress-${cardId}`"
    :data-state="status"
    class="flex min-w-0 flex-col bg-slate-900 rounded-2xl p-5 border border-slate-800 hover:border-cyan-500/30 transition-colors"
  >
    <h4 class="text-sm font-semibold text-slate-200 uppercase tracking-wider mb-3 truncate" :title="title">{{ title }}</h4>
    <div class="min-w-0 flex-1">
      <div v-if="status === 'loading'" class="flex items-center py-2">
        <LoadingSpinner size="sm" />
      </div>
      <p v-else-if="status === 'unknown'" class="text-sm text-amber-500">{{ t('dashboard.bridge.unknown') }}</p>
      <p v-else-if="status === 'empty'" class="text-sm text-slate-500">{{ emptyLabel }}</p>
      <slot v-else />
    </div>
    <RouterLink
      :to="linkTo"
      data-testid="dashboard-progress-link"
      class="mt-4 self-start text-xs font-medium uppercase tracking-wider text-cyan-400 hover:text-cyan-300 transition-colors"
    >{{ linkLabel }}</RouterLink>
  </section>
</template>
