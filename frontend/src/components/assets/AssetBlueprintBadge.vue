<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { AssetBlueprint } from '@/types'

const props = defineProps<{
  blueprint: AssetBlueprint
}>()

const { t } = useI18n()

const label = computed(() => {
  const { status, runs, materialEfficiency, timeEfficiency } = props.blueprint
  if (status === 'not_synced') {
    return t('assets.blueprint.notSynced')
  }
  const efficiencies = `ME${materialEfficiency}/TE${timeEfficiency}`
  if (status === 'copy') {
    return `BPC · ${t('assets.blueprint.runs', { count: runs })} · ${efficiencies}`
  }
  return `BPO · ${efficiencies}`
})

const toneClass = computed(() => {
  if (props.blueprint.status === 'not_synced') return 'text-amber-400 border-amber-500/30'
  if (props.blueprint.status === 'copy') return 'text-cyan-400 border-cyan-500/30'
  return 'text-emerald-400 border-emerald-500/30'
})
</script>

<template>
  <span
    data-testid="asset-blueprint"
    :class="['text-xs font-mono px-1.5 py-0.5 rounded-sm border bg-slate-900/60 whitespace-nowrap', toneClass]"
  >{{ label }}</span>
</template>
