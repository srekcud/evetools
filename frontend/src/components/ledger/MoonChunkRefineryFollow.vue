<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import MoonChunkName from './MoonChunkName.vue'

defineProps<{
  structureId: number
  name: string | null
  followed: boolean
  pending: boolean
}>()

defineEmits<{ toggle: [] }>()

const { t } = useI18n()
</script>

<template>
  <li class="flex items-center justify-between gap-3 rounded-sm border border-slate-800 bg-slate-900 px-3 py-2">
    <span class="min-w-0 text-sm text-slate-200"><MoonChunkName :name="name" :id="structureId" /></span>
    <button
      type="button"
      :data-refinery-follow="structureId"
      :aria-pressed="followed"
      :disabled="pending"
      :class="[
        'shrink-0 rounded-sm border px-2 py-0.5 text-xs font-medium uppercase tracking-wider transition-colors disabled:cursor-not-allowed disabled:opacity-40',
        followed ? 'border-cyan-500/60 bg-cyan-500/10 text-cyan-300' : 'border-slate-700 text-slate-400 hover:text-slate-200',
      ]"
      @click="$emit('toggle')"
    >
      <span aria-hidden="true">{{ followed ? '★' : '☆' }} </span><span data-follow-label>{{ followed ? t('ledger.mining.moonChunks.unfollow') : t('ledger.mining.moonChunks.follow') }}</span>
    </button>
  </li>
</template>
