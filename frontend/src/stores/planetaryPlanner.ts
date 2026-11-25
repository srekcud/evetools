import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import {
  DEFAULT_SETTINGS,
  buildPlan,
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
    return
  }

  function addProduction(typeId: number): void {
    void typeId
  }

  function setTarget(index: number, value: number): boolean {
    void index
    void value
    return true
  }

  function removeProduction(index: number): void {
    void index
  }

  function selectProduction(index: number): void {
    void index
  }

  function setChoice(typeId: number, choice: BuildChoice): void {
    void typeId
    void choice
  }

  function applyCheapest(): void {
    return
  }

  function setPriceSource(source: PriceSource): void {
    void source
  }

  function setTaxPercent(value: number): boolean {
    void value
    return true
  }

  function setDaysBetweenRounds(value: number): boolean {
    void value
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
