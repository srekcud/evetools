<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ShoppingListFailedProduct } from '@/stores/industry/types'

const props = defineProps<{
  failedProducts: ShoppingListFailedProduct[]
  productNamesByTypeId: ReadonlyMap<number, string>
  message?: string
}>()

const { t } = useI18n()

const warningMessage = computed(() => props.message ?? t('industry.shoppingTab.failedProductsWarning'))
</script>

<template>
  <div
    v-if="failedProducts.length > 0"
    class="bg-amber-900/30 border border-amber-500/50 rounded-lg p-3 flex items-start gap-3"
    data-testid="failed-products-warning"
  >
    <svg class="w-5 h-5 text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
    </svg>
    <div class="text-sm">
      <p class="text-amber-300">{{ warningMessage }}</p>
      <ul class="mt-1 space-y-0.5 text-xs text-amber-400/70">
        <li v-for="failedProduct in failedProducts" :key="failedProduct.typeId">
          <span class="text-amber-300">{{ productNamesByTypeId.get(failedProduct.typeId) ?? failedProduct.typeId }}</span>
          : {{ failedProduct.reason }}
        </li>
      </ul>
    </div>
  </div>
</template>
