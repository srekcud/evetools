<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFormatters } from '@/composables/useFormatters'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, UnclaimedStockAllocationMode } from '@/stores/group-industry/types'
import AllocateUnclaimedStockModal from './AllocateUnclaimedStockModal.vue'

const props = defineProps<{
  item: BomItem
}>()

const { t } = useI18n()
const { formatIsk, formatNumber } = useFormatters()
const projectStore = useGroupProjectStore()

const allocationMode = ref<UnclaimedStockAllocationMode | null>(null)
const isOwner = computed(() => projectStore.currentProject?.myRole === 'owner')
const isLaunchPriceKnown = computed(() => props.item.launchPrice != null)
</script>

<template>
  <div
    v-if="item.unclaimedQuantity != null && item.unclaimedQuantity > 0"
    data-testid="unclaimed-stock-badge"
    class="mt-1 inline-flex flex-wrap items-center gap-x-1.5 gap-y-1 rounded border border-amber-500/30 bg-amber-500/10 px-1.5 py-0.5 text-xs text-amber-300"
    :title="t('groupIndustry.bom.unclaimedStockHint')"
  >
    <span class="font-mono">{{ t('groupIndustry.bom.unclaimedStock', { quantity: formatNumber(item.unclaimedQuantity, 0) }) }}</span>
    <span
      v-if="item.unclaimedValue != null"
      data-testid="unclaimed-stock-value"
      class="font-mono text-amber-200/80"
    >{{ t('groupIndustry.bom.unclaimedStockValue', { value: formatIsk(item.unclaimedValue, 1) }) }}</span>
    <span
      v-else
      data-testid="unclaimed-stock-launch-price-unknown"
      class="text-amber-200/80"
    >{{ t('groupIndustry.bom.unclaimedStockLaunchPriceUnknown') }}</span>
    <template v-if="isOwner">
      <button
        type="button"
        data-testid="unclaimed-stock-assign"
        :disabled="!isLaunchPriceKnown"
        class="rounded border border-amber-500/40 px-1.5 text-amber-200 transition-colors hover:bg-amber-500/20 disabled:cursor-not-allowed disabled:opacity-40"
        @click="allocationMode = 'assign'"
      >{{ t('groupIndustry.bom.unclaimedStockAssign') }}</button>
      <button
        type="button"
        data-testid="unclaimed-stock-donate"
        class="rounded border border-amber-500/40 px-1.5 text-amber-200 transition-colors hover:bg-amber-500/20"
        @click="allocationMode = 'donate'"
      >{{ t('groupIndustry.bom.unclaimedStockDonate') }}</button>
    </template>
  </div>
  <AllocateUnclaimedStockModal
    v-if="allocationMode !== null && projectStore.currentProject !== null"
    :project-id="projectStore.currentProject.id"
    :item="item"
    :mode="allocationMode"
    @close="allocationMode = null"
    @done="allocationMode = null"
  />
</template>
