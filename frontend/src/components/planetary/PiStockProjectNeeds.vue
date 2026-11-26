<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="pi-stock-project-needs">
    <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.stock.projectNeeds.title') }}</h3>
  </section>
</template>
