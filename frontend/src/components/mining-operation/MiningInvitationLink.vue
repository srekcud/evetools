<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { miningOperationJoinUrl } from '@/views/MiningOperation/miningOperationJoinUrl'

const COPIED_FEEDBACK_MS = 2000

const props = defineProps<{
  joinCode: string
  canRenew?: boolean
}>()

const emit = defineEmits<{
  renew: []
}>()

const { t } = useI18n()
const titleId = useId()
const copied = ref(false)
const copyFailed = ref(false)
let copiedTimeout: ReturnType<typeof setTimeout> | null = null

const joinUrl = computed(() => miningOperationJoinUrl(window.location.origin, props.joinCode))

async function copyToClipboard(): Promise<void> {
  copyFailed.value = false
  try {
    await navigator.clipboard.writeText(joinUrl.value)
  } catch {
    copyFailed.value = true
    return
  }
  copied.value = true
  if (copiedTimeout) clearTimeout(copiedTimeout)
  copiedTimeout = setTimeout(() => { copied.value = false }, COPIED_FEEDBACK_MS)
}

onBeforeUnmount(() => {
  if (copiedTimeout) clearTimeout(copiedTimeout)
})
</script>

<template>
  <section class="eve-card p-5">
    <h2 :id="titleId" class="mb-3 font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.invite.title') }}</h2>
    <div class="flex items-center gap-2">
      <input
        type="text"
        :value="joinUrl"
        :aria-labelledby="titleId"
        readonly
        class="min-w-0 flex-1 truncate rounded-md border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-sm text-cyan-200"
      />
      <button
        type="button"
        class="shrink-0 rounded-md px-4 py-2 text-sm font-medium transition-colors"
        :class="copied ? 'bg-emerald-600 text-white' : 'bg-slate-700 text-slate-100 hover:bg-slate-600'"
        @click="copyToClipboard"
      >{{ copied ? t('miningOperation.invite.copied') : t('common.actions.copy') }}</button>
    </div>
    <p v-if="copyFailed" class="mt-2 text-xs text-red-400" role="alert">{{ t('common.errors.copyFailed') }}</p>
    <p class="mt-3 text-xs text-slate-400">{{ t('miningOperation.invite.hint') }}</p>
    <button
      v-if="canRenew"
      type="button"
      class="mt-3 text-xs font-medium text-cyan-400 underline hover:text-cyan-300"
      @click="emit('renew')"
    >{{ t('miningOperation.invite.renew') }}</button>
  </section>
</template>
