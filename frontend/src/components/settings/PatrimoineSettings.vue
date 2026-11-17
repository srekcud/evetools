<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useLedgerStore } from '@/stores/ledger'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LedgerSettingsTab from '@/components/ledger/LedgerSettingsTab.vue'

const ledgerStore = useLedgerStore()

const settings = computed(() => ledgerStore.settings)

async function updateSetting(key: string, value: string): Promise<void> {
  try {
    await ledgerStore.updateSettings({ [key]: value })
  } catch (e) {
    console.error('Failed to update settings:', e)
  }
}

onMounted(() => {
  void ledgerStore.fetchSettings()
})
</script>

<template>
  <div class="space-y-6">
    <LedgerSettingsTab :settings="settings" @update-setting="updateSetting" />

    <ErrorBanner v-if="ledgerStore.error" :message="ledgerStore.error" @dismiss="ledgerStore.clearError()" />
  </div>
</template>
