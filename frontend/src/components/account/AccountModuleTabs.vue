<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ModuleTabBar, { type ModuleTab } from '@/components/layout/ModuleTabBar.vue'

export type AccountModulePage = 'characters' | 'settings'

defineProps<{
  active: AccountModulePage
}>()

const { t } = useI18n()

const tabs = computed<ModuleTab[]>(() => [
  { id: 'characters', label: t('nav.characters'), to: { name: 'characters' } },
  { id: 'settings', label: t('settings.title'), to: { name: 'settings' } },
])
</script>

<template>
  <ModuleTabBar :tabs="tabs" :active-id="active" :aria-label="t('nav.account')" class="mb-6" />
</template>
