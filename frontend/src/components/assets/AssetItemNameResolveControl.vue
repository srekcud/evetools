<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import LoadingSpinner from '@/components/common/LoadingSpinner.vue'
import type { Asset, ItemNameResolution } from '@/types'

const props = defineProps<{
  asset: Asset
  resolution: ItemNameResolution | undefined
}>()

const emit = defineEmits<{
  resolve: [asset: Asset]
}>()

const { t } = useI18n()

function requestResolution(): void {
  emit('resolve', props.asset)
}
</script>

<template>
  <span
    v-if="resolution?.status === 'unnamed'"
    :data-testid="`item-name-unnamed-${asset.id}`"
    class="text-xs text-slate-500"
  >{{ t('assets.itemName.unnamed') }}</span>
  <template v-else-if="asset.canResolveItemName && resolution?.status !== 'named'">
    <button
      type="button"
      :data-testid="`resolve-item-name-${asset.id}`"
      :disabled="resolution?.status === 'loading'"
      :title="t('assets.itemName.resolve')"
      :aria-label="t('assets.itemName.resolve')"
      class="inline-flex items-center justify-center w-5 h-5 rounded-sm transition-colors shrink-0 text-slate-500 hover:text-cyan-400 hover:bg-slate-700/50 disabled:opacity-60 disabled:hover:text-slate-500 disabled:hover:bg-transparent"
      @click.stop="requestResolution"
    >
      <LoadingSpinner v-if="resolution?.status === 'loading'" size="xs+" />
      <svg
        v-else
        aria-hidden="true"
        class="w-3.5 h-3.5"
        fill="none"
        stroke="currentColor"
        viewBox="0 0 24 24"
      >
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536M9 13l6.768-6.768a2.5 2.5 0 113.536 3.536L12.536 16.536 8 18l1-5z" />
      </svg>
    </button>
    <span
      v-if="resolution?.status === 'error'"
      :data-testid="`item-name-error-${asset.id}`"
      class="text-xs text-red-400 break-words"
    >{{ resolution.message }}</span>
  </template>
</template>
