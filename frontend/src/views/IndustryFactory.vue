<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import IndustryModuleTabs from '@/components/industry-module/IndustryModuleTabs.vue'
import FactoryBlueprints from '@/components/industry-factory/FactoryBlueprints.vue'
import FactoryPersonalStock from '@/components/industry-factory/FactoryPersonalStock.vue'
import FactoryPlayerLines from '@/components/industry-factory/FactoryPlayerLines.vue'
import FactoryProjectStock from '@/components/industry-factory/FactoryProjectStock.vue'
import FactoryStationsTable from '@/components/industry-factory/FactoryStationsTable.vue'
import FactoryStockpile from '@/components/industry-factory/FactoryStockpile.vue'

const { t } = useI18n()
</script>

<template>
  <MainLayout>
    <div>
      <IndustryModuleTabs active="factory" />

      <h1 class="sr-only">{{ t('industryFactory.title') }}</h1>

      <FactoryStationsTable />
      <FactoryStockpile />
      <FactoryProjectStock />
      <FactoryPlayerLines />
      <FactoryBlueprints />
      <FactoryPersonalStock />
    </div>
  </MainLayout>
</template>
