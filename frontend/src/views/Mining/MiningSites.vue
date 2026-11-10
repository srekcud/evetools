<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import MiningModuleTabs from '@/components/mining-module/MiningModuleTabs.vue'
import MiningSiteHistory from '@/components/mining-site/MiningSiteHistory.vue'

const { t } = useI18n()
</script>

<template>
  <MainLayout>
    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
      <MiningModuleTabs active="sites" />

      <h1 class="sr-only">{{ t('miningModule.pages.sites') }}</h1>

      <MiningSiteHistory />
    </div>
  </MainLayout>
</template>
