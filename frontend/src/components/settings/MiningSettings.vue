<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useMiningValuationSettings } from '@/composables/useMiningValuationSettings'
import { useReprocessingProfile } from '@/composables/useReprocessingProfile'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import MiningValuationControls from '@/components/ledger/MiningValuationControls.vue'
import MiningValuationChoices from '@/components/ledger/MiningValuationChoices.vue'
import ReprocessingProfilePanel from '@/components/ledger/ReprocessingProfilePanel.vue'

const { t } = useI18n()
const authStore = useAuthStore()
const { selectedStructure, exportTax, selectStructure, clearStructure, setExportTax } = useMiningValuationSettings()
const { profile, hasError: hasProfileError, load: loadProfile, save: saveProfile, refresh: refreshProfile } = useReprocessingProfile()

const characters = computed(() => authStore.user?.characters ?? [])

onMounted(loadProfile)
</script>

<template>
  <div class="space-y-4">
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
    <MiningValuationChoices />
    <ErrorBanner v-if="hasProfileError" :message="t('ledger.mining.reprocessingProfile.requestError')" />
    <ReprocessingProfilePanel :profile="profile" :characters="characters" @save="saveProfile" @refresh="refreshProfile" />
  </div>
</template>
