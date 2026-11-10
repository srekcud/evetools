<script setup lang="ts">
import type { MiningHaulerPlan } from '@/stores/miningOperation'
import type { CompanionSizeKey } from '@/views/MiningSite/miningCompanion'

defineProps<{
  plan: MiningHaulerPlan
  size: CompanionSizeKey
  canDeclareEmptied: boolean
}>()

defineEmits<{ 'declare-emptied': [] }>()
</script>

<template>
  <div data-testid="companion-hauler" />
</template>
