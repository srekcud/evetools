<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ModuleTabBar, { type ModuleTab } from '@/components/layout/ModuleTabBar.vue'

export type PveModulePage = 'revenue' | 'escalations'

defineProps<{
  active: PveModulePage
}>()

const { t } = useI18n()

const tabs = computed<ModuleTab[]>(() => [
  { id: 'revenue', label: t('pveModule.tabs.revenue'), to: { name: 'pve-revenue' } },
  { id: 'escalations', label: t('pveModule.tabs.escalations'), to: { name: 'pve-escalations' } },
])
</script>

<template>
  <ModuleTabBar :tabs="tabs" :active-id="active" :aria-label="t('nav.pve')" settings-section="pve" class="mb-6" />
</template>
