<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { MiningOperationChunk } from '@/stores/miningOperation'
import { formatEveDateTime } from '@/views/MiningOperation/miningOperationTime'

defineProps<{
  chunk: MiningOperationChunk
}>()

const { t } = useI18n()
</script>

<template>
  <section class="eve-card flex flex-wrap items-center justify-between gap-x-6 gap-y-2 p-4" data-testid="operation-linked-chunk">
    <h2 class="font-display text-sm font-semibold uppercase tracking-[0.2em] text-slate-300">{{ t('miningOperation.linkedChunk.title') }}</h2>
    <dl class="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-6 gap-y-1 text-sm">
      <div class="flex min-w-0 items-baseline gap-2">
        <dt class="font-mono text-xs text-slate-500">{{ t('miningOperation.linkedChunk.moon') }}</dt>
        <dd class="min-w-0 truncate text-slate-100" :title="chunk.moonName ?? String(chunk.moonId)">{{ chunk.moonName ?? chunk.moonId }}</dd>
      </div>
      <div class="flex min-w-0 items-baseline gap-2">
        <dt class="font-mono text-xs text-slate-500">{{ t('miningOperation.linkedChunk.refinery') }}</dt>
        <dd class="min-w-0 truncate text-slate-300" :title="chunk.structureName ?? String(chunk.structureId)">{{ chunk.structureName ?? chunk.structureId }}</dd>
      </div>
      <div class="flex items-baseline gap-2">
        <dt class="font-mono text-xs text-slate-500">{{ t('miningOperation.linkedChunk.arrival') }}</dt>
        <dd class="font-mono text-xs text-cyan-200">{{ formatEveDateTime(chunk.chunkArrivalTime) }} EVE</dd>
      </div>
    </dl>
    <router-link :to="{ name: 'mining-moons' }" class="text-sm text-cyan-300 hover:text-cyan-200">{{ t('miningOperation.linkedChunk.open') }}</router-link>
  </section>
</template>
