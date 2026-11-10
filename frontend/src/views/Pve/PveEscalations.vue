<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import EscalationsTab from '@/components/ledger/EscalationsTab.vue'
import PveModuleTabs from '@/components/pve-module/PveModuleTabs.vue'

const { t } = useI18n()
</script>

<template>
  <MainLayout>
    <div>
      <PveModuleTabs active="escalations" />

      <h1 class="sr-only">{{ t('pveModule.pages.escalations') }}</h1>

      <EscalationsTab />
    </div>
  </MainLayout>
</template>
