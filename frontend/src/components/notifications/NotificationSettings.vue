<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import BaseModal from '@/components/common/BaseModal.vue'
import NotificationPreferencesForm from '@/components/notifications/NotificationPreferencesForm.vue'

defineProps<{
  inline?: boolean
}>()

const emit = defineEmits<{
  close: []
}>()

const { t } = useI18n()
</script>

<template>
  <NotificationPreferencesForm v-if="inline" :show-cancel="false" />
  <BaseModal
    v-else
    :show="true"
    :title="t('notificationHub.settingsModal.title')"
    panel-class="border-cyan-500/30 shadow-cyan-500/10 max-w-lg max-h-[85vh] flex flex-col"
    @close="emit('close')"
  >
    <!-- Header -->
    <template #title="{ titleId }">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800 shrink-0">
        <h3 :id="titleId" class="text-lg font-semibold text-slate-100">{{ t('notificationHub.settingsModal.title') }}</h3>
        <button
          @click="emit('close')"
          class="p-1 hover:bg-slate-800 rounded-lg transition-colors"
          :aria-label="t('common.actions.close')"
        >
          <svg aria-hidden="true" class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </template>

    <NotificationPreferencesForm show-cancel @cancel="emit('close')" @saved="emit('close')" />
  </BaseModal>
</template>
