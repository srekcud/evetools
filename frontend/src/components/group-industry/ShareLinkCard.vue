<script setup lang="ts">
import { ref, computed } from 'vue'

const props = defineProps<{
  shortLinkCode: string
}>()

const copied = ref(false)

const joinUrl = computed(() =>
  `${window.location.origin}/join/${props.shortLinkCode}`,
)

async function copyToClipboard(): Promise<void> {
  try {
    await navigator.clipboard.writeText(joinUrl.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    // Fallback for older browsers
    const textarea = document.createElement('textarea')
    textarea.value = joinUrl.value
    document.body.appendChild(textarea)
    textarea.select()
    document.execCommand('copy')
    document.body.removeChild(textarea)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  }
}
</script>

<template>
  <div class="bg-slate-800/60 rounded-xl border border-slate-700/50 p-5">
    <h4 class="text-sm font-semibold text-slate-200 mb-3">Share Join Link</h4>
    <div class="flex items-center gap-2 mb-3">
      <input
        type="text"
        :value="joinUrl"
        readonly
        class="flex-1 bg-slate-900 border border-slate-700 rounded-lg px-4 py-2.5 text-sm text-slate-300 font-mono"
        style="font-variant-numeric: tabular-nums;"
      />
      <button
        class="px-4 py-2.5 rounded-lg text-sm font-medium flex items-center gap-2 transition-all hover:-translate-y-px"
        :class="copied ? 'bg-emerald-600 text-white' : 'bg-slate-700 hover:bg-slate-600 text-slate-200'"
        @click="copyToClipboard"
      >
        <!-- Copy icon -->
        <svg v-if="!copied" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" />
        </svg>
        <!-- Check icon -->
        <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
        {{ copied ? 'Copied!' : 'Copy' }}
      </button>
    </div>
    <p class="text-xs text-slate-500">Members from your corporation join instantly. Others require your approval.</p>
  </div>
</template>
