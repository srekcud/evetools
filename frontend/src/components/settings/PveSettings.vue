<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLedgerStore } from '@/stores/ledger'
import { usePveTypeSettings } from '@/composables/usePveTypeSettings'
import ErrorBanner from '@/components/common/ErrorBanner.vue'
import LedgerSettingsTab from '@/components/ledger/LedgerSettingsTab.vue'
import TypeConfigDialog from '@/components/ledger/pve/TypeConfigDialog.vue'

const TYPE_BUTTON_CLASS = 'px-4 py-2 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg text-slate-300 text-sm font-medium'

const { t } = useI18n()
const ledgerStore = useLedgerStore()
const typeSettings = usePveTypeSettings(() => undefined)

const settings = computed(() => ledgerStore.settings)
const showAmmoConfig = ref(false)
const showLootConfig = ref(false)

async function updateSetting(key: string, value: string): Promise<void> {
  try {
    await ledgerStore.updateSettings({ [key]: value })
  } catch (e) {
    console.error('Failed to update settings:', e)
  }
}

onMounted(() => {
  void ledgerStore.fetchSettings()
  void typeSettings.fetchSettings()
})
</script>

<template>
  <div class="space-y-6">
    <LedgerSettingsTab :settings="settings" @update-setting="updateSetting" />

    <div class="bg-slate-900 rounded-xl p-6 border border-slate-800">
      <h3 class="text-lg font-semibold text-white mb-4">{{ t('settings.pve.typesTitle') }}</h3>
      <div class="flex flex-wrap gap-3">
        <button
          type="button"
          :class="TYPE_BUTTON_CLASS"
          :title="t('pve.configAmmo')"
          @click="showAmmoConfig = true"
        >{{ t('pve.expenseTypes.ammo') }}</button>
        <button
          type="button"
          :class="TYPE_BUTTON_CLASS"
          :title="t('pve.configLootTypes')"
          @click="showLootConfig = true"
        >{{ t('pve.lootTypesTitle') }}</button>
      </div>
    </div>

    <ErrorBanner v-if="ledgerStore.error" :message="ledgerStore.error" @dismiss="ledgerStore.clearError()" />

    <TypeConfigDialog
      :visible="showAmmoConfig"
      :title="t('pve.ammoConfigTitle')"
      :description="t('pve.ammoConfigDescription')"
      :search-placeholder="t('pve.searchAmmoPlaceholder')"
      accent-color="cyan"
      :configured-types="typeSettings.ammoTypes.value"
      :is-searching="typeSettings.isSearchingAmmo.value"
      :is-adding="typeSettings.isAddingAmmo.value"
      :search-results="typeSettings.ammoSearchResults.value"
      @close="showAmmoConfig = false"
      @search="typeSettings.searchAmmoTypes"
      @add="typeSettings.addAmmoType"
      @remove="typeSettings.removeAmmoType"
    />

    <TypeConfigDialog
      :visible="showLootConfig"
      :title="t('pve.lootTypesTitle')"
      :description="t('pve.lootTypesDescription')"
      :search-placeholder="t('pve.searchLootPlaceholder')"
      accent-color="amber"
      :configured-types="typeSettings.lootTypes.value"
      :is-searching="typeSettings.isSearchingLoot.value"
      :is-adding="typeSettings.isAddingLootType.value"
      :search-results="typeSettings.lootSearchResults.value"
      @close="showLootConfig = false"
      @search="typeSettings.searchLootTypes"
      @add="typeSettings.addLootType"
      @remove="typeSettings.removeLootType"
    >
      <template #empty-message>
        {{ t('pve.noLootTypeConfigured') }}
      </template>
    </TypeConfigDialog>
  </div>
</template>
