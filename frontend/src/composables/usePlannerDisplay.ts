import { useFormatters } from '@/composables/useFormatters'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { indexCatalog, type PlannerType } from '@/utils/planetaryPlanner'

const ISK_THRESHOLD_FOR_SHORT_FORM = 1000
const SMALL_AMOUNT_DECIMALS = 2

export function usePlannerDisplay() {
  const store = usePlanetaryPlannerStore()
  const { formatIsk, formatNumber } = useFormatters()

  function typeOf(typeId: number): PlannerType {
    if (!store.catalog) throw new Error('The planner catalog is not loaded')
    const type = indexCatalog(store.catalog).typeById.get(typeId)
    if (!type) throw new Error(`Type ${typeId} is missing from the planner catalog`)
    return type
  }

  function formatPlannerIsk(amount: number): string {
    return Math.abs(amount) < ISK_THRESHOLD_FOR_SHORT_FORM ? formatNumber(amount, SMALL_AMOUNT_DECIMALS) : formatIsk(amount)
  }

  return { typeOf, formatPlannerIsk, formatNumber }
}
