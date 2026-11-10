<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import MiningHaulerBar from '@/components/mining-operation/MiningHaulerBar.vue'
import { useMiningHaulerText } from '@/composables/useMiningHaulerText'
import type { MiningHaulerPlan } from '@/stores/miningOperation'
import type { CompanionSizeKey } from '@/views/MiningSite/miningCompanion'

defineProps<{
  plan: MiningHaulerPlan
  size: CompanionSizeKey
  canDeclareEmptied: boolean
}>()

defineEmits<{ 'declare-emptied': [] }>()

const { t } = useI18n()
const { wholeOrUnknown, originText } = useMiningHaulerText()
</script>

<template>
  <div class="space-y-1 rounded-sm border border-slate-800 px-2 py-1.5" data-testid="companion-hauler">
    <MiningHaulerBar :plan="plan" :compact="size !== 'detail'" />
    <div v-if="size === 'detail'" class="flex items-center justify-between gap-2 text-xs text-slate-400">
      <div v-if="plan.state === 'known'" class="min-w-0 space-y-0.5">
        <p class="truncate" data-testid="hauler-flow" :title="t('miningOperation.haulerPlan.flow', { inflow: wholeOrUnknown(plan.inflowM3PerMinute) })">{{ t('miningOperation.haulerPlan.flow', { inflow: wholeOrUnknown(plan.inflowM3PerMinute) }) }}</p>
        <p class="truncate" data-testid="hauler-emptied-at" :title="originText(plan)">{{ originText(plan) }}</p>
      </div>
      <button
        v-if="canDeclareEmptied"
        type="button"
        class="ml-auto shrink-0 rounded-sm border border-cyan-500/40 px-2 py-1 text-cyan-200 hover:bg-cyan-500/10"
        data-testid="hauler-emptied-button"
        @click="$emit('declare-emptied')"
      >{{ t('miningOperation.haulerPlan.emptied') }}</button>
    </div>
  </div>
</template>
