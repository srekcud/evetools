<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <span
    data-testid="companion-here-badge"
    class="flex-shrink-0 border border-emerald-400/50 px-1 text-xs font-semibold leading-tight text-emerald-300"
  >{{ t('groupIndustry.companion.hereBadge') }}</span>
</template>
