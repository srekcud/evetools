<script setup lang="ts">
import { useToast } from '@/composables/useToast'

const { toasts } = useToast()
</script>

<template>
  <Teleport to="body">
    <div class="fixed top-6 right-6 z-[100] flex flex-col gap-3 pointer-events-none">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        class="pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-xl border backdrop-blur-xl shadow-xl max-w-sm transform transition-transform duration-300"
        :class="{
          'bg-emerald-500/20 border-emerald-500/40 text-emerald-400': toast.type === 'success',
          'bg-amber-500/20 border-amber-500/40 text-amber-400': toast.type === 'warning',
          'bg-red-500/20 border-red-500/40 text-red-400': toast.type === 'error',
          'translate-x-0': toast.visible,
          'translate-x-[120%]': !toast.visible,
        }"
      >
        <span class="flex-shrink-0">
          <!-- Success icon -->
          <svg v-if="toast.type === 'success'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <!-- Warning icon -->
          <svg v-else-if="toast.type === 'warning'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
          <!-- Error icon -->
          <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
        </span>
        <p class="text-sm font-medium">{{ toast.message }}</p>
      </div>
    </div>
  </Teleport>
</template>
