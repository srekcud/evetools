<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ModuleTabBar, { type ModuleTab } from '@/components/layout/ModuleTabBar.vue'

export type IndustryModulePage = 'workshop' | 'projects' | 'analysis' | 'production'

defineProps<{
  active: IndustryModulePage
}>()

const { t } = useI18n()

const tabs = computed<ModuleTab[]>(() => [
  { id: 'workshop', label: t('industryModule.tabs.workshop'), to: { name: 'industry' } },
  { id: 'projects', label: t('industryModule.tabs.projects'), to: { name: 'industry-projects' } },
  { id: 'analysis', label: t('industryModule.tabs.analysis'), to: { name: 'industry-analysis' } },
  { id: 'production', label: t('industryModule.tabs.production'), to: { name: 'industry-production' } },
])
</script>

<template>
  <ModuleTabBar :tabs="tabs" :active-id="active" :aria-label="t('nav.industry')" settings-section="industry" class="mb-6" />
</template>
