<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import MainLayout from '@/layouts/MainLayout.vue'
import MiningValuationControls from '@/components/ledger/MiningValuationControls.vue'
import OreValueTab from '@/components/ledger/OreValueTab.vue'
import MiningModuleTabs from '@/components/mining-module/MiningModuleTabs.vue'
import { useMiningValuationSettings } from '@/composables/useMiningValuationSettings'

const { t } = useI18n()
const { selectedStructure, selectedStructureId, exportTax, selectStructure, clearStructure, setExportTax } = useMiningValuationSettings()
</script>

<template>
  <MainLayout>
    <div class="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">
      <MiningModuleTabs active="ore-value" />

      <h1 class="sr-only">{{ t('miningModule.pages.oreValue') }}</h1>

      <div class="space-y-1">
        <MiningValuationControls
          :structure="selectedStructure"
          :export-tax="exportTax"
          @select="selectStructure"
          @clear="clearStructure"
          @update:export-tax="setExportTax"
        />
        <p class="text-xs text-slate-400">{{ t('miningModule.oreValue.valuationHint') }}</p>
      </div>

      <OreValueTab :structure-id="selectedStructureId" />
    </div>
  </MainLayout>
</template>
