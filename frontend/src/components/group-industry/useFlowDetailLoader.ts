import { computed, ref, watch } from 'vue'
import { useGroupContractSalesStore } from '@/stores/group-industry/contractSales'
import { useGroupContributionStore } from '@/stores/group-industry/contribution'
import { useGroupCourierStore } from '@/stores/group-industry/courier'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupInventionStore } from '@/stores/group-industry/invention'
import { useGroupLineBlueprintsStore } from '@/stores/group-industry/lineBlueprints'
import { useGroupMaterialSourcingStore } from '@/stores/group-industry/materialSourcing'
import { useGroupPlanningStore } from '@/stores/group-industry/planning'
import { useGroupResearchStore } from '@/stores/group-industry/research'
import { useGroupSellForecastStore } from '@/stores/group-industry/sellForecast'
import { useProjectSellOrdersStore } from '@/stores/group-industry/sellOrders'
import { useGroupStructureHealthStore } from '@/stores/group-industry/structureHealth'
import { detailStoreKeysOf, type DetailBlockKind, type DetailStoreKey } from './projectFlowCardDetail'

export interface DetailBlockStatus {
  loading: boolean
  error: string | null
}

interface StoreReader {
  read: (projectId: string) => Promise<void>
  error: () => string | null
}

interface FlowDetailLoaderOptions {
  projectId: () => string
  kinds: () => DetailBlockKind[]
  canRecordSales: () => boolean
}

export function useFlowDetailLoader({ projectId, kinds, canRecordSales }: FlowDetailLoaderOptions) {
  const sourcingStore = useGroupMaterialSourcingStore()
  const courierStore = useGroupCourierStore()
  const lineBlueprintsStore = useGroupLineBlueprintsStore()
  const researchStore = useGroupResearchStore()
  const planningStore = useGroupPlanningStore()
  const structureHealthStore = useGroupStructureHealthStore()
  const sellForecastStore = useGroupSellForecastStore()
  const sellOrdersStore = useProjectSellOrdersStore()
  const contributionStore = useGroupContributionStore()
  const distributionStore = useGroupDistributionStore()
  const contractSalesStore = useGroupContractSalesStore()
  const inventionStore = useGroupInventionStore()

  const readers: Record<DetailStoreKey, StoreReader> = {
    sourcing: { read: sourcingStore.fetchMaterialSourcing, error: () => sourcingStore.error },
    courier: { read: courierStore.fetchCourier, error: () => courierStore.error },
    lineBlueprints: { read: lineBlueprintsStore.fetchLineBlueprints, error: () => lineBlueprintsStore.error },
    research: { read: researchStore.fetchResearch, error: () => researchStore.error },
    planning: { read: planningStore.fetchPlanning, error: () => planningStore.error },
    structureHealth: { read: structureHealthStore.fetchStructureHealth, error: () => structureHealthStore.error },
    sellForecast: { read: sellForecastStore.fetchForProject, error: () => sellForecastStore.error },
    sellOrders: { read: sellOrdersStore.fetchSellOrders, error: () => sellOrdersStore.error },
    contributions: { read: contributionStore.fetchContributions, error: () => contributionStore.error },
    saleSuggestions: { read: distributionStore.fetchSaleSuggestions, error: () => distributionStore.saleSuggestionsError },
    contractSales: { read: contractSalesStore.fetchForProject, error: () => contractSalesStore.error },
    invention: { read: () => Promise.resolve(), error: () => inventionStore.error },
  }

  const settledKeys = ref<DetailStoreKey[]>([])
  const requestedKeys = new Set<DetailStoreKey>()
  const neededKeys = computed(() => [...new Set(kinds().flatMap(kind => detailStoreKeysOf(kind, canRecordSales())))])

  async function read(keys: DetailStoreKey[]): Promise<void> {
    await Promise.all(keys.map(async (key) => {
      await readers[key].read(projectId())
      if (!settledKeys.value.includes(key)) settledKeys.value = [...settledKeys.value, key]
    }))
  }

  watch(neededKeys, (keys) => {
    const unrequested = keys.filter(key => !requestedKeys.has(key))
    for (const key of unrequested) requestedKeys.add(key)
    void read(unrequested)
  }, { immediate: true })

  function statusOf(kind: DetailBlockKind): DetailBlockStatus {
    const keys = detailStoreKeysOf(kind, canRecordSales())
    return {
      loading: keys.some(key => !settledKeys.value.includes(key)),
      error: keys.map(key => readers[key].error()).find(error => error !== null) ?? null,
    }
  }

  function refresh(): Promise<void> {
    return read(neededKeys.value)
  }

  return { statusOf, refresh }
}
