import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePlanetaryStockStore } from '@/stores/planetaryStock'
import { usePlanetaryStockPricesStore } from '@/stores/planetaryStockPrices'
import { buildStockTodo } from '@/utils/planetaryStockTodo'

export function usePlanetaryStockTodo() {
  const { t } = useI18n()
  const stockStore = usePlanetaryStockStore()
  const pricesStore = usePlanetaryStockPricesStore()

  return computed(() => buildStockTodo(stockStore.rows, pricesStore.rows, (key, params) => t(key, params ?? {})))
}
