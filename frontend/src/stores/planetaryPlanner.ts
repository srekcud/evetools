import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'
import {
  DEFAULT_VOLUME_SHARE_PERCENT,
  isValidVolumeShare,
  rankProducts,
  rankingBaseCount,
  type PlannerMarket,
  type RankingBase,
  type RankingRow,
  type RankingTierFilter,
} from '@/utils/planetaryRanking'
import {
  buildPlanetPlan,
  isValidExtractionYield,
  type PlanetPlan,
  type PlannerAccount,
} from '@/utils/planetaryPlanets'
import {
  DEFAULT_SETTINGS,
  buildPlan,
  cheapestChoices,
  defaultTargetPerDay,
  indexCatalog,
  isValidDaysBetweenRounds,
  isValidTarget,
  isValidTaxPercent,
  type BuildChoice,
  type PlannedProduction,
  type PlannerCatalog,
  type PlannerPlan,
  type PlannerSettings,
  type PriceSource,
} from '@/utils/planetaryPlanner'

export const usePlanetaryPlannerStore = defineStore('planetaryPlanner', () => {
  const catalog = ref<PlannerCatalog | null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)
  const productions = ref<PlannedProduction[]>([])
  const choices = ref<Record<number, BuildChoice>>({})
  const settings = ref<PlannerSettings>({ ...DEFAULT_SETTINGS })
  const selectedIndex = ref<number | null>(null)
  const account = ref<PlannerAccount | null>(null)
  const market = ref<PlannerMarket | null>(null)
  const rankingBase = ref<RankingBase>('planet')
  const volumeSharePercent = ref(DEFAULT_VOLUME_SHARE_PERCENT)
  const rankingTier = ref<RankingTierFilter>('all')
  const extractionYieldOverrides = ref<Record<number, number>>({})

  const plan = computed<PlannerPlan | null>(() =>
    catalog.value ? buildPlan(productions.value, catalog.value, choices.value, settings.value) : null,
  )

  const planetPlan = computed<PlanetPlan | null>(() =>
    catalog.value
      ? buildPlanetPlan(productions.value, catalog.value, choices.value, settings.value, account.value, extractionYieldOverrides.value)
      : null,
  )

  const ranking = computed<RankingRow[]>(() =>
    catalog.value
      ? rankProducts(catalog.value, settings.value, market.value, {
          baseCount: rankingBaseCount(rankingBase.value, planetPlan.value),
          volumeSharePercent: volumeSharePercent.value,
          tier: rankingTier.value,
        })
      : [],
  )

  async function fetchMarket(): Promise<void> {
    try {
      const response = await apiRequest<PlannerMarket>('/planetary/planner/market')
      if (!Array.isArray(response.products)) throw new Error('The market response has no product list')
      market.value = { regionId: response.regionId, products: response.products }
    } catch {
      market.value = null
      if (error.value === null) error.value = i18n.global.t('pi.planner.errors.market')
    }
  }

  function setRankingBase(base: RankingBase): boolean {
    if (rankingBaseCount(base, planetPlan.value) === null) return false

    rankingBase.value = base
    return true
  }

  function setVolumeShare(value: number): boolean {
    if (!isValidVolumeShare(value)) return false

    volumeSharePercent.value = value
    return true
  }

  function setRankingTier(tier: RankingTierFilter): void {
    rankingTier.value = tier
  }

  async function fetchAccount(): Promise<void> {
    try {
      const response = await apiRequest<PlannerAccount>('/planetary/planner/account')
      account.value = {
        usedPlanets: response.usedPlanets,
        characters: response.characters,
        extractionYields: response.extractionYields,
      }
    } catch {
      account.value = null
      if (error.value === null) error.value = i18n.global.t('pi.planner.errors.account')
    }
  }

  function setExtractionYield(typeId: number, value: number): boolean {
    if (!isValidExtractionYield(value)) return false

    extractionYieldOverrides.value = { ...extractionYieldOverrides.value, [typeId]: value }
    return true
  }

  async function fetchCatalog(): Promise<void> {
    isLoading.value = true
    error.value = null

    try {
      const response = await apiRequest<PlannerCatalog>('/planetary/planner')
      if (response.recipes.length === 0) {
        catalog.value = null
        error.value = i18n.global.t('pi.planner.errors.emptyCatalog')
        return
      }
      catalog.value = {
        recipes: response.recipes,
        types: response.types,
        prices: response.prices,
        planetTypes: response.planetTypes,
        extractionPlanetTypes: response.extractionPlanetTypes,
      }
    } catch (e) {
      error.value = e instanceof Error ? e.message : i18n.global.t('pi.planner.errors.load')
    } finally {
      isLoading.value = false
    }
  }

  function clearError(): void {
    error.value = null
  }

  function addProduction(typeId: number, targetPerDay?: number): void {
    if (!catalog.value) return
    const recipe = indexCatalog(catalog.value).recipeByOutput.get(typeId)
    if (!recipe) return
    if (targetPerDay !== undefined && !isValidTarget(targetPerDay)) return

    productions.value.push({ typeId, targetPerDay: targetPerDay === undefined ? defaultTargetPerDay(recipe) : targetPerDay })
    selectedIndex.value = productions.value.length - 1
  }

  function setTarget(index: number, value: number): boolean {
    const production = productions.value[index]
    if (!production || !isValidTarget(value)) return false

    production.targetPerDay = value
    return true
  }

  function removeProduction(index: number): void {
    if (index < 0 || index >= productions.value.length) return

    productions.value.splice(index, 1)
    const selected = selectedIndex.value
    if (productions.value.length === 0) {
      selectedIndex.value = null
    } else if (selected !== null && selected > index) {
      selectedIndex.value = selected - 1
    } else if (selected === index) {
      selectedIndex.value = Math.min(index, productions.value.length - 1)
    }
  }

  function selectProduction(index: number): void {
    if (index >= 0 && index < productions.value.length) selectedIndex.value = index
  }

  function setChoice(typeId: number, choice: BuildChoice): void {
    choices.value = { ...choices.value, [typeId]: choice }
  }

  function applyCheapest(): void {
    if (!catalog.value) return
    choices.value = cheapestChoices(productions.value, catalog.value, choices.value, settings.value)
  }

  function setPriceSource(source: PriceSource): void {
    settings.value = { ...settings.value, priceSource: source }
  }

  function setTaxPercent(value: number): boolean {
    if (!isValidTaxPercent(value)) return false
    settings.value = { ...settings.value, taxPercent: value }
    return true
  }

  function setDaysBetweenRounds(value: number): boolean {
    if (!isValidDaysBetweenRounds(value)) return false
    settings.value = { ...settings.value, daysBetweenRounds: value }
    return true
  }

  return {
    catalog,
    isLoading,
    error,
    productions,
    choices,
    settings,
    selectedIndex,
    account,
    market,
    rankingBase,
    volumeSharePercent,
    rankingTier,
    ranking,
    fetchMarket,
    setRankingBase,
    setVolumeShare,
    setRankingTier,
    extractionYieldOverrides,
    plan,
    planetPlan,
    fetchCatalog,
    fetchAccount,
    setExtractionYield,
    clearError,
    addProduction,
    setTarget,
    removeProduction,
    selectProduction,
    setChoice,
    applyCheapest,
    setPriceSource,
    setTaxPercent,
    setDaysBetweenRounds,
  }
})
