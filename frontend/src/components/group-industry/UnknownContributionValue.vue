<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <span
    :title="t('common.unknownValue')"
    class="inline-block px-1.5 py-0.5 border border-dashed border-violet-400 text-violet-300 rounded-sm text-xs font-sans"
  >—</span>
</template>
