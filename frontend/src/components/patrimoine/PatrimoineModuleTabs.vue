<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ModuleTabBar, { type ModuleTab } from '@/components/layout/ModuleTabBar.vue'

export type PatrimoineModulePage = 'balance' | 'assets'

defineProps<{
  active: PatrimoineModulePage
}>()

const { t } = useI18n()

const tabs = computed<ModuleTab[]>(() => [
  { id: 'balance', label: t('nav.balance'), to: { name: 'balance' } },
  { id: 'assets', label: t('nav.inventory'), to: { name: 'assets' } },
])
</script>

<template>
  <ModuleTabBar :tabs="tabs" :active-id="active" :aria-label="t('nav.patrimoine')" class="mb-6" />
</template>
