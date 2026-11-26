<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <div data-testid="pi-stock-banner" :aria-label="t('pi.stock.grid.title')"></div>
</template>
