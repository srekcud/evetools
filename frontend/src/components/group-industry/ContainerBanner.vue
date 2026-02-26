<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGroupProjectStore } from '@/stores/group-industry/project'

const { t } = useI18n()
const projectStore = useGroupProjectStore()

const props = defineProps<{
  containerName: string
  projectId: string
}>()

const isVerifying = ref(false)

async function verifyAll(): Promise<void> {
  isVerifying.value = true
  try {
    await projectStore.fetchContainerVerification(props.projectId)
  } finally {
    isVerifying.value = false
  }
}
</script>

<template>
  <div class="bg-violet-500/5 rounded-xl border border-violet-500/20 p-3 mb-4 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <div class="w-7 h-7 rounded-lg bg-violet-500/12 flex items-center justify-center flex-shrink-0">
        <svg class="w-3.5 h-3.5 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
        </svg>
      </div>
      <div>
        <span class="text-sm text-violet-400 font-medium">Container: "{{ containerName }}"</span>
        <span class="text-xs text-slate-500 ml-2">linked to corp offices</span>
      </div>
    </div>
    <button
      @click="verifyAll"
      :disabled="isVerifying"
      class="px-3 py-1.5 bg-violet-600/20 hover:bg-violet-600/30 rounded-lg text-violet-400 text-xs font-medium flex items-center gap-1.5 border border-violet-500/25 transition-colors disabled:opacity-50"
    >
      <!-- Spinner when verifying -->
      <svg v-if="isVerifying" class="w-3.5 h-3.5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
      </svg>
      <!-- Check icon when idle -->
      <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      {{ isVerifying ? t('groupIndustry.container.verifying') : t('groupIndustry.container.verifyAll') }}
    </button>
  </div>
</template>
