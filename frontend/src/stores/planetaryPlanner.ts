import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { i18n } from '@/i18n'
import { compareFactories, followedTargets, type FactoryGap, type FollowedTargets, type FollowTier } from '@/utils/planetaryFollow'
import { apiRequest } from '@/services/api'
import {
  MAX_TEMPLATE_NAME_LENGTH,
  isTemplateResolvable,
  loadTemplate,
  mergeTemplate,
  templateToPayload,
  type PlanTemplate,
} from '@/utils/planetaryTemplates'
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
  type PlanetMode,
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
  type PlannerHub,
  type PurchaseSource,
  type SaleSource,
} from '@/utils/planetaryPlanner'

const TEMPLATES_ENDPOINT = '/planetary/templates'
const PLANET_MODES: readonly PlanetMode[] = ['auto', 'perTier', 'wholeChain']

function twoDigits(value: number): string {
  return String(value).padStart(2, '0')
}

function defaultFollowedPlanName(): string {
  const today = new Date()
  const date = `${twoDigits(today.getDate())}/${twoDigits(today.getMonth() + 1)}/${today.getFullYear()}`
  return i18n.global.t('pi.planner.follow.defaultName', { date })
}

export const usePlanetaryPlannerStore = defineStore('planetaryPlanner', () => {
  const catalog = ref<PlannerCatalog | null>(null)
  const hub = ref<PlannerHub | null>(null)
  const isLoading = ref(false)
  const error = ref<string | null>(null)
  const productions = ref<PlannedProduction[]>([])
  const choices = ref<Record<number, BuildChoice>>({})
  const settings = ref<PlannerSettings>({ ...DEFAULT_SETTINGS })
  const selectedIndex = ref<number | null>(null)
  const account = ref<PlannerAccount | null>(null)
  const market = ref<PlannerMarket | null>(null)
  const templates = ref<PlanTemplate[]>([])
  const rankingBase = ref<RankingBase>('planet')
  const volumeSharePercent = ref(DEFAULT_VOLUME_SHARE_PERCENT)
  const rankingTier = ref<RankingTierFilter>('all')
  const extractionYieldOverrides = ref<Record<number, number>>({})
  const planetMode = ref<PlanetMode>('auto')

  const plan = computed<PlannerPlan | null>(() =>
    catalog.value ? buildPlan(productions.value, catalog.value, choices.value, settings.value) : null,
  )

  const planetPlan = computed<PlanetPlan | null>(() =>
    catalog.value
      ? buildPlanetPlan(productions.value, catalog.value, choices.value, settings.value, account.value, extractionYieldOverrides.value, planetMode.value)
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

  const followedTemplate = computed<PlanTemplate | null>(() => templates.value.find(template => template.followed) ?? null)

  const followedTargetsOfPlan = computed<FollowedTargets | null>(() => {
    const template = followedTemplate.value
    if (!template || !catalog.value || !isTemplateResolvable(template, catalog.value)) return null
    return followedTargets(template, catalog.value)
  })

  const factoryComparison = computed<FactoryGap[]>(() =>
    plan.value ? compareFactories(plan.value.summary.factoriesByTier, account.value === null ? null : account.value.factories) : [],
  )

  const followedFactoryComparison = computed<FactoryGap[]>(() =>
    followedTargetsOfPlan.value ? compareFactories(followedTargetsOfPlan.value.factoriesByTier, account.value === null ? null : account.value.factories) : [],
  )

  const followedPlanTiers = computed<FollowTier[] | null>(() =>
    followedTargetsOfPlan.value === null ? null : followedFactoryComparison.value.filter(gap => gap.planned > 0).map(gap => gap.tier),
  )

  async function setFollowed(id: string, followed: boolean): Promise<boolean> {
    try {
      await apiRequest<PlanTemplate>(`${TEMPLATES_ENDPOINT}/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({ followed }),
      })
      templates.value = templates.value.map(template => {
        if (template.id === id) return { ...template, followed }
        return followed ? { ...template, followed: false } : template
      })
      return true
    } catch {
      error.value = i18n.global.t('pi.planner.errors.templateFollow')
      return false
    }
  }

  async function followTemplate(id: string): Promise<boolean> {
    return setFollowed(id, true)
  }

  async function unfollowTemplate(id: string): Promise<boolean> {
    return setFollowed(id, false)
  }

  async function followCurrentPlan(name?: string): Promise<boolean> {
    if (productions.value.length === 0) return false

    const trimmedName = name === undefined ? '' : name.trim()
    const saved = await saveTemplate(trimmedName === '' ? defaultFollowedPlanName() : trimmedName)
    if (!saved) return false

    return followTemplate(templates.value[0].id)
  }

  async function loadFollowContext(): Promise<void> {
    await Promise.all([fetchCatalog(), fetchAccount(), fetchTemplates()])
  }

  async function fetchTemplates(): Promise<void> {
    try {
      const response = await apiRequest<PlanTemplate[]>(TEMPLATES_ENDPOINT)
      if (!Array.isArray(response)) throw new Error('The templates response is not a list')
      templates.value = response
    } catch {
      if (error.value === null) error.value = i18n.global.t('pi.planner.errors.templatesRead')
    }
  }

  async function saveTemplate(name: string): Promise<boolean> {
    const trimmedName = name.trim()
    if (productions.value.length === 0 || trimmedName === '' || trimmedName.length > MAX_TEMPLATE_NAME_LENGTH) return false

    try {
      const saved = await apiRequest<PlanTemplate>(TEMPLATES_ENDPOINT, {
        method: 'POST',
        body: JSON.stringify(templateToPayload(productions.value, choices.value, settings.value, trimmedName)),
      })
      templates.value = [saved, ...templates.value]
      return true
    } catch {
      error.value = i18n.global.t('pi.planner.errors.templateSave')
      return false
    }
  }

  async function deleteTemplate(id: string): Promise<boolean> {
    try {
      await apiRequest<null>(`${TEMPLATES_ENDPOINT}/${id}`, { method: 'DELETE' })
      templates.value = templates.value.filter(template => template.id !== id)
      return true
    } catch {
      error.value = i18n.global.t('pi.planner.errors.templateDelete')
      return false
    }
  }

  function resolvableTemplate(id: string): PlanTemplate | null {
    const template = templates.value.find(candidate => candidate.id === id)
    if (!template || !catalog.value || !isTemplateResolvable(template, catalog.value)) return null
    return template
  }

  function loadTemplateIntoPlan(id: string): void {
    const template = resolvableTemplate(id)
    if (!template) return

    const loaded = loadTemplate(template)
    productions.value = loaded.productions
    choices.value = loaded.choices
    settings.value = loaded.settings
    selectedIndex.value = loaded.productions.length === 0 ? null : 0
  }

  function addTemplateToPlan(id: string): void {
    const template = resolvableTemplate(id)
    if (!template) return
    if (productions.value.length === 0) {
      loadTemplateIntoPlan(id)
      return
    }

    const merged = mergeTemplate(productions.value, choices.value, template)
    productions.value = merged.productions
    choices.value = merged.choices
  }

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
        factories: response.factories,
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
      const response = await apiRequest<PlannerCatalog & { hub: PlannerHub }>('/planetary/planner')
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
      hub.value = response.hub
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

  function setPurchaseSource(source: PurchaseSource): void {
    settings.value = { ...settings.value, purchaseSource: source }
  }

  function setSaleSource(source: SaleSource): void {
    settings.value = { ...settings.value, saleSource: source }
  }

  function setTaxPercent(value: number): boolean {
    if (!isValidTaxPercent(value)) return false
    settings.value = { ...settings.value, taxPercent: value }
    return true
  }

  function setPlanetMode(mode: PlanetMode): void {
    if (!PLANET_MODES.includes(mode)) throw new Error(`Unknown planet mode ${String(mode)}`)
    planetMode.value = mode
  }

  function setDaysBetweenRounds(value: number): boolean {
    if (!isValidDaysBetweenRounds(value)) return false
    settings.value = { ...settings.value, daysBetweenRounds: value }
    return true
  }

  return {
    catalog,
    hub,
    isLoading,
    error,
    productions,
    choices,
    settings,
    selectedIndex,
    account,
    market,
    templates,
    followedTemplate,
    factoryComparison,
    followedFactoryComparison,
    followedPlanTiers,
    followedTargetsOfPlan,
    followTemplate,
    unfollowTemplate,
    followCurrentPlan,
    loadFollowContext,
    fetchTemplates,
    saveTemplate,
    deleteTemplate,
    loadTemplateIntoPlan,
    addTemplateToPlan,
    rankingBase,
    volumeSharePercent,
    rankingTier,
    ranking,
    fetchMarket,
    setRankingBase,
    setVolumeShare,
    setRankingTier,
    extractionYieldOverrides,
    planetMode,
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
    setPurchaseSource,
    setSaleSource,
    setTaxPercent,
    setDaysBetweenRounds,
    setPlanetMode,
  }
})
