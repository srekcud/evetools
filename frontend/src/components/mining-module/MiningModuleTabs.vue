<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ModuleTabBar, { type ModuleTab } from '@/components/layout/ModuleTabBar.vue'

export type MiningModulePage = 'operations' | 'moons' | 'sites' | 'ore-value' | 'my-mining'

defineProps<{
  active: MiningModulePage
}>()

const { t } = useI18n()

const tabs = computed<ModuleTab[]>(() => [
  { id: 'operations', label: t('miningModule.tabs.operations'), to: { name: 'mining-operations' } },
  { id: 'moons', label: t('miningModule.tabs.moons'), to: { name: 'mining-moons' } },
  { id: 'sites', label: t('miningModule.tabs.sites'), to: { name: 'mining-sites' } },
  { id: 'ore-value', label: t('miningModule.tabs.oreValue'), to: { name: 'mining-ore-value' } },
  { id: 'my-mining', label: t('miningModule.tabs.myMining'), to: { name: 'mining-my-mining' } },
])
</script>

<template>
  <ModuleTabBar :tabs="tabs" :active-id="active" :aria-label="t('nav.mining')" settings-section="mining" class="mb-6" />
</template>
