<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { RigOption } from '@/stores/industry/types'

defineProps<{
  rig: RigOption
}>()

const { t } = useI18n()
</script>

<template>
  <span class="text-table-cell">
    <span v-if="rig.bonus > 0" class="text-emerald-400">{{ t('industry.structures.rigBonus.material', { value: rig.bonus }) }}</span>
    <span v-if="rig.timeBonus !== undefined" class="text-amber-400 ml-1">{{ t('industry.structures.rigBonus.time', { value: rig.timeBonus }) }}</span>
    <span v-if="rig.costBonus !== undefined" class="text-cyan-400 ml-1">{{ t('industry.structures.rigBonus.cost', { value: rig.costBonus }) }}</span>
    <span class="text-slate-500 ml-1">({{ rig.size }})</span>
  </span>
</template>
