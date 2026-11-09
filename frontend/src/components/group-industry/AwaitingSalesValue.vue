<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <span class="text-cyan-300/80">{{ t('groupIndustry.distribution.awaitingSales') }}</span>
</template>
