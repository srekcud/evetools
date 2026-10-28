<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  coverages: Map<number, number>
  typeNames: Map<number, string>
}>()

const { t } = useI18n()
</script>

<template>
  <div v-if="coverages.size > 0" class="eve-card p-4 border border-amber-500/30">
    <p class="text-sm font-semibold text-amber-400" :title="t('industry.partialPrice.tooltip')">{{ t('industry.partialPrice.noticeTitle') }}</p>
    <ul class="mt-2 space-y-1 text-xs text-amber-300/80">
      <li v-for="[typeId, coveragePercent] in coverages" :key="typeId">
        {{ t('industry.partialPrice.noticeLine', { type: typeNames.get(typeId) || t('common.unknownTypeName', { typeId }), percent: coveragePercent }) }}
      </li>
    </ul>
  </div>
</template>
