import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { i18n } from '@/i18n'
import { apiRequest } from '@/services/api'
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

  const plan = computed<PlannerPlan | null>(() =>
    catalog.value ? buildPlan(productions.value, catalog.value, choices.value, settings.value) : null,
  )

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
      catalog.value = { recipes: response.recipes, types: response.types, prices: response.prices }
    } catch (e) {
      error.value = e instanceof Error ? e.message : i18n.global.t('pi.planner.errors.load')
    } finally {
      isLoading.value = false
    }
  }

  function clearError(): void {
    error.value = null
  }

  function addProduction(typeId: number): void {
    if (!catalog.value) return
    const recipe = indexCatalog(catalog.value).recipeByOutput.get(typeId)
    if (!recipe) return

    productions.value.push({ typeId, targetPerDay: defaultTargetPerDay(recipe) })
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
    plan,
    fetchCatalog,
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
