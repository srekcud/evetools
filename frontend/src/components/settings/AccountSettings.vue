<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useCorporationAssetVisibility } from '@/composables/useCorporationAssetVisibility'
import CorpVisibilityPanel from '@/components/assets/CorpVisibilityPanel.vue'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'

const { t } = useI18n()
const { visibility, visibilityPanel, isDirector, loadFailed, saveError, fetchVisibility, saveVisibility } = useCorporationAssetVisibility()
const isLoading = ref(true)

onMounted(async () => {
  await fetchVisibility()
  isLoading.value = false
})
</script>

<template>
  <div class="space-y-3">
    <div v-if="isLoading" class="flex items-center gap-2 text-sm text-slate-500 py-2">
      <LoadingSpinner size="sm" class="text-cyan-500" />
      {{ t('settings.account.loading') }}
    </div>
    <p v-else-if="loadFailed" data-testid="settings-account-load-error" class="text-sm text-amber-500">{{ t('settings.account.loadError') }}</p>
    <CorpVisibilityPanel
      v-else-if="visibility && isDirector"
      ref="visibilityPanel"
      :visibility="visibility"
      @save="saveVisibility"
    />
    <p v-else data-testid="settings-account-unavailable" class="text-sm text-slate-400">{{ t('settings.account.unavailable') }}</p>
    <p v-if="saveError !== ''" class="text-sm text-red-400">{{ saveError }}</p>
  </div>
</template>
