<script setup lang="ts">
import type { CorporationMiningExtraction, MoonChunkValue } from '@/composables/useCorporationMiningExtractions'
import MoonChunkValuePanel from './MoonChunkValuePanel.vue'

defineProps<{
  corporationId: number
  extraction: CorporationMiningExtraction
  value: MoonChunkValue
  timeZone: string
  columnCount: number
}>()

defineEmits<{ recorded: [] }>()
</script>

<template>
  <tr :data-detail="extraction.id" class="bg-slate-950/60">
    <td :colspan="columnCount" class="px-4 py-4">
      <MoonChunkValuePanel
        :corporation-id="corporationId"
        :extraction="extraction"
        :value="value"
        :time-zone="timeZone"
        @recorded="$emit('recorded')"
      />
    </td>
  </tr>
</template>
