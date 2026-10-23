<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  loading?: boolean
}>()

const { t } = useI18n()
</script>

<template>
  <span v-if="loading" aria-busy="true" class="inline-block h-[1em] w-16 align-middle rounded bg-slate-700/60 animate-pulse"></span>
  <span v-else :title="t('common.unknownValue')">—</span>
</template>
