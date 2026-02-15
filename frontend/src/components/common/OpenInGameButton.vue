<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useOpenWindow } from '@/composables/useOpenWindow'

const props = defineProps<{
  type: 'market' | 'info' | 'contract'
  targetId: number
}>()

const { t } = useI18n()
const { openMarket, openInfo, openContract, isActionLoading } = useOpenWindow()

const status = ref<'idle' | 'success' | 'error'>('idle')
let statusTimeout: ReturnType<typeof setTimeout> | null = null

function getTooltip(): string {
  switch (props.type) {
    case 'market': return t('openWindow.market')
    case 'info': return t('openWindow.info')
    case 'contract': return t('openWindow.contract')
  }
}

async function handleClick(event: Event): Promise<void> {
  event.stopPropagation()

  if (isActionLoading(props.type, props.targetId)) return

  if (statusTimeout) {
    clearTimeout(statusTimeout)
    statusTimeout = null
  }

  let success = false
  switch (props.type) {
    case 'market':
      success = await openMarket(props.targetId)
      break
    case 'info':
      success = await openInfo(props.targetId)
      break
    case 'contract':
      success = await openContract(props.targetId)
      break
  }

  status.value = success ? 'success' : 'error'
  statusTimeout = setTimeout(() => {
    status.value = 'idle'
  }, 1500)
}
</script>

<template>
  <button
    @click="handleClick"
    :disabled="isActionLoading(type, targetId)"
    :title="status === 'success' ? t('openWindow.success') : status === 'error' ? t('openWindow.error') : getTooltip()"
    class="inline-flex items-center justify-center w-5 h-5 rounded-sm transition-colors shrink-0"
    :class="[
      status === 'success'
        ? 'text-emerald-400'
        : status === 'error'
          ? 'text-red-400'
          : 'text-slate-500 hover:text-cyan-400 hover:bg-slate-700/50',
    ]"
  >
    <!-- Loading spinner -->
    <svg
      v-if="isActionLoading(type, targetId)"
      class="w-3.5 h-3.5 animate-spin"
      fill="none"
      viewBox="0 0 24 24"
    >
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
    </svg>
    <!-- Success check -->
    <svg
      v-else-if="status === 'success'"
      class="w-3.5 h-3.5"
      fill="none"
      stroke="currentColor"
      viewBox="0 0 24 24"
    >
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
    </svg>
    <!-- Error X -->
    <svg
      v-else-if="status === 'error'"
      class="w-3.5 h-3.5"
      fill="none"
      stroke="currentColor"
      viewBox="0 0 24 24"
    >
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
    </svg>
    <!-- Default: external/game icon -->
    <svg
      v-else
      class="w-3.5 h-3.5"
      fill="none"
      stroke="currentColor"
      viewBox="0 0 24 24"
    >
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
    </svg>
  </button>
</template>
