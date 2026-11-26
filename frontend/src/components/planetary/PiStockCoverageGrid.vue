<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const emit = defineEmits<{ 'open-planner': [] }>()
const { t } = useI18n()

function openPlanner(): void {
  emit('open-planner')
}
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="pi-stock-grid">
    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.stock.grid.title') }}</h3>
    <button type="button" class="hidden" @click="openPlanner"></button>
  </section>
</template>
