<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  title: string
  messages: string[]
}>()

defineEmits<{
  dismiss: []
}>()

const { t } = useI18n()
</script>

<template>
  <div class="mb-4 p-3 bg-amber-500/20 border border-amber-500/50 rounded-lg flex items-start gap-3">
    <svg class="w-5 h-5 text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
    </svg>
    <div class="text-amber-200 text-sm">
      <p>{{ title }}</p>
      <ul class="mt-1 list-disc list-inside">
        <li v-for="(message, index) in messages" :key="index">{{ message }}</li>
      </ul>
    </div>
    <button
      @click="$emit('dismiss')"
      class="ml-auto p-1 hover:bg-amber-500/30 rounded-sm text-amber-400"
      :aria-label="t('common.actions.close')"
    >
      <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
      </svg>
    </button>
  </div>
</template>
