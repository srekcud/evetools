<script setup lang="ts">
import type { MiningHaulerPlan } from '@/stores/miningOperation'

defineProps<{
  plan: MiningHaulerPlan
  canDeclareEmptied: boolean
  typeNames: Record<number, string>
}>()

defineEmits<{ 'declare-emptied': [] }>()
</script>

<template>
  <div data-testid="hauler-plan" />
</template>
