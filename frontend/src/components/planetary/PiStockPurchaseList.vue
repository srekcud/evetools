<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import UnknownValue from '@/components/common/UnknownValue.vue'
import { useCopyFeedback } from '@/composables/useCopyFeedback'
import { useFormatters } from '@/composables/useFormatters'
import { usePlanetaryStockStore } from '@/stores/planetaryStock'
import { multibuyText } from '@/utils/multibuy'

const VOLUME_DECIMALS = 2

const { t } = useI18n()
const { formatNumber } = useFormatters()
const store = usePlanetaryStockStore()
const { copied, failed, copy } = useCopyFeedback()

function copyList(): void {
  copy(multibuyText(store.purchaseList))
}
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-5" data-testid="pi-stock-purchase-list">
    <div class="mb-4 flex items-center justify-between gap-3">
      <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-300">{{ t('pi.stock.purchase.title') }}</h3>
      <div v-if="store.purchaseList.length > 0" class="flex items-center gap-3">
        <span v-if="failed" class="text-xs text-amber-300" role="alert">{{ t('pi.stock.purchase.copyFailed') }}</span>
        <button
          type="button"
          class="rounded-md border border-cyan-500/40 bg-cyan-500/10 px-3 py-1.5 text-xs font-medium uppercase tracking-wider text-cyan-300 transition-colors hover:bg-cyan-500/20"
          data-testid="stock-purchase-copy"
          @click="copyList"
        >{{ copied ? t('pi.stock.purchase.copied') : t('pi.stock.purchase.copy') }}</button>
      </div>
    </div>

    <p v-if="store.purchaseList.length === 0" class="text-sm text-slate-500" data-testid="stock-purchase-empty">{{ t('pi.stock.purchase.empty') }}</p>

    <template v-else>
      <ul class="space-y-1.5">
        <li
          v-for="line in store.purchaseList"
          :key="line.typeId"
          class="flex items-center justify-between gap-4 text-sm"
          :data-testid="`stock-purchase-line-${line.typeId}`"
        >
          <span class="text-slate-200">{{ line.typeName }}</span>
          <span class="font-mono-tech text-cyan-400">{{ formatNumber(line.quantity, 0) }}</span>
        </li>
      </ul>
      <p class="mt-4 flex items-center justify-between border-t border-slate-800 pt-3 text-sm" data-testid="stock-purchase-volume">
        <span class="text-xs uppercase tracking-wider text-slate-500">{{ t('pi.stock.purchase.totalVolume') }}</span>
        <span class="font-mono-tech text-slate-300">
          <UnknownValue v-if="store.purchaseVolume === null" />
          <template v-else>{{ formatNumber(store.purchaseVolume, VOLUME_DECIMALS) }} m³</template>
        </span>
      </p>
    </template>
  </section>
</template>
