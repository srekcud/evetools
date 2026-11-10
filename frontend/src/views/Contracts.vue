<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import CommerceModuleTabs from '@/components/commerce/CommerceModuleTabs.vue'
import ContractsTab from '@/components/assets/ContractsTab.vue'

const { t } = useI18n()
</script>

<template>
  <MainLayout>
    <div>
      <CommerceModuleTabs active="contracts" />
      <h1 class="mb-6 text-2xl font-bold text-slate-100">{{ t('commerce.tabs.contracts') }}</h1>
      <ContractsTab />
    </div>
  </MainLayout>
</template>
