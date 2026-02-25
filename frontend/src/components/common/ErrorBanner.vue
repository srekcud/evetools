<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'

const props = withDefaults(defineProps<{
  message: string
  autoDismissMs?: number
}>(), {
  autoDismissMs: 0,
})

const emit = defineEmits<{
  dismiss: []
}>()

let timeoutId: ReturnType<typeof setTimeout> | null = null

onMounted(() => {
  if (props.autoDismissMs > 0) {
    timeoutId = setTimeout(() => {
      emit('dismiss')
    }, props.autoDismissMs)
  }
})

onUnmounted(() => {
  if (timeoutId !== null) {
    clearTimeout(timeoutId)
  }
})
</script>

<template>
  <div class="bg-red-500/15 border border-red-500/30 rounded-xl p-4 flex items-center gap-3">
    <svg class="w-5 h-5 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
    </svg>
    <p class="text-sm text-red-400 flex-1">{{ message }}</p>
    <button @click="emit('dismiss')" class="p-1 hover:bg-red-500/20 rounded-lg transition-colors text-red-400">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
      </svg>
    </button>
  </div>
</template>
