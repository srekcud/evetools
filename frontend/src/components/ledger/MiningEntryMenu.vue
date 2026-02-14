<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { MiningEntry } from '@/stores/ledger'

defineProps<{
  entryId: string
  position: { x: number; y: number }
}>()

const emit = defineEmits<{
  'set-usage': [usage: MiningEntry['usage']]
  close: []
}>()

const { t } = useI18n()
</script>

<template>
  <Teleport to="body">
    <div
      class="entry-menu fixed z-50 w-56 bg-slate-800 border border-slate-700 rounded-lg shadow-xl overflow-hidden"
      :style="{ left: `${position.x}px`, top: `${position.y}px` }"
    >
      <div class="p-2 border-b border-slate-800">
        <p class="text-xs text-slate-500 px-2">{{ t('ledger.mining.changeStatus') }}</p>
      </div>
      <div class="p-1">
        <button
          @click="emit('set-usage', 'sold')"
          class="w-full px-3 py-2 text-left text-sm rounded-md hover:bg-slate-700/50 flex items-center gap-3 transition-colors"
        >
          <span class="w-2 h-2 bg-emerald-400 rounded-full"></span>
          <span class="text-white">{{ t('ledger.mining.usage.sold') }}</span>
        </button>
        <button
          @click="emit('set-usage', 'corp_project')"
          class="w-full px-3 py-2 text-left text-sm rounded-md hover:bg-slate-700/50 flex items-center gap-3 transition-colors"
        >
          <span class="w-2 h-2 bg-amber-400 rounded-full"></span>
          <span class="text-white">{{ t('ledger.mining.usage.corpProject') }}</span>
        </button>
        <button
          @click="emit('set-usage', 'unknown')"
          class="w-full px-3 py-2 text-left text-sm rounded-md hover:bg-slate-700/50 flex items-center gap-3 transition-colors"
        >
          <span class="w-2 h-2 bg-slate-500 rounded-full"></span>
          <span class="text-white">{{ t('ledger.mining.usage.unknown') }}</span>
        </button>
      </div>
    </div>
  </Teleport>
</template>
