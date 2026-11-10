<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ModuleTabBar, { type ModuleTab } from '@/components/layout/ModuleTabBar.vue'

export type CommerceModulePage = 'market' | 'appraisal' | 'contracts'

defineProps<{
  active: CommerceModulePage
}>()

const { t } = useI18n()

const tabs = computed<ModuleTab[]>(() => [
  { id: 'market', label: t('commerce.tabs.market'), to: { name: 'market' } },
  { id: 'appraisal', label: t('commerce.tabs.appraisal'), to: { name: 'appraisal' } },
  { id: 'contracts', label: t('commerce.tabs.contracts'), to: { name: 'contracts' } },
])
</script>

<template>
  <ModuleTabBar :tabs="tabs" :active-id="active" :aria-label="t('nav.commerce')" settings-section="market" class="mb-6" />
</template>
