/**
 * Backward-compatible re-exports from the split industry stores.
 *
 * Existing code importing from '@/stores/industry' will continue to work.
 * New code should import directly from '@/stores/industry/projects', etc.
 */
import { ref } from 'vue'
import { useProjectsStore } from './industry/projects'
import { useStepsStore } from './industry/steps'
import { useStructuresStore } from './industry/structures'
import { useBlacklistStore } from './industry/blacklist'
import type { NavigationIntent } from './industry/types'

export { enrichStep, enrichProject, formatErrorMessage } from './industry/compat'

// Module-level ref shared across all useIndustryStore() calls
const navigationIntent = ref<NavigationIntent>(null)

// Re-export all types for backward compatibility
export type {
  RootProduct,
  IndustryProjectStep,
  IndustryProject,
  ShoppingListItem,
  ShoppingListTotals,
  ShoppingListResponse,
  ProductionTreeNode,
  TreeMaterial,
  SearchResult,
  BlacklistCategory,
  BlacklistItem,
  BlacklistConfig,
  StructureConfig,
  StructureSearchResult,
  RigOption,
  RigOptions,
  CorporationStructureSharedConfig,
  CorporationStructure,
  JobMatch,
  StepPurchase,
  CharacterSkill,
  UserSettings,
  PurchaseSuggestion,
  AvailableJob,
  SimilarEsiJob,
  CostEstimationMaterial,
  CostEstimationStep,
  CostEstimation,
  CopyCostEntry,
  CopyCosts,
  BpcKitDatacore,
  BpcKitDecryptorOption,
  BpcKitInvention,
  BpcKitSummary,
  BpcKit,
  ProfitMarginResult,
  ProfitMarginSellPrices,
  MarginEntry,
  ProfitMarginMaterial,
  ProfitMarginJobStep,
  ProfitMarginInventionOption,
  BatchScanItem,
  BuyVsBuildComponent,
  BuyVsBuildMaterial,
  BuyVsBuildResult,
  PivotAnalysisResult,
  PivotCandidate,
  PivotSourceProduct,
  PivotComponentCoverage,
  PivotMissingComponent,
  NavigationIntent,
} from './industry/types'

// Legacy type aliases for old field names (backward compat)
export type SimilarJob = import('./industry/types').SimilarEsiJob

/**
 * Composite store that combines all industry sub-stores.
 * This provides backward compatibility with the old monolithic useIndustryStore.
 *
 * Uses getters/setters to maintain reactivity on state properties.
 */
export function useIndustryStore() {
  const projectsStore = useProjectsStore()
  const stepsStore = useStepsStore()
  const structuresStore = useStructuresStore()
  const blacklistStore = useBlacklistStore()

  return {
    // Projects store - reactive state via getters/setters
    get projects() { return projectsStore.projects },
    get currentProject() { return projectsStore.currentProject },
    get searchResults() { return projectsStore.searchResults },
    set searchResults(v) { projectsStore.searchResults = v },
    get isLoading() { return projectsStore.isLoading },
    get error() { return projectsStore.error },
    set error(v) { projectsStore.error = v },
    get totalProfit() { return projectsStore.totalProfit },
    get costEstimation() { return projectsStore.costEstimation },
    get bpcKit() { return projectsStore.bpcKit },
    get copyCosts() { return projectsStore.copyCosts },
    get marginResult() { return projectsStore.marginResult },
    get marginLoading() { return projectsStore.marginLoading },
    get defaultMaxJobDurationDays() { return projectsStore.defaultMaxJobDurationDays },
    setDefaultMaxJobDurationDays: projectsStore.setDefaultMaxJobDurationDays,
    searchProducts: projectsStore.searchProducts,
    createProject: projectsStore.createProject,
    fetchProjects: projectsStore.fetchProjects,
    fetchProject: projectsStore.fetchProject,
    updateProject: projectsStore.updateProject,
    deleteProject: projectsStore.deleteProject,
    matchJobs: projectsStore.matchJobs,
    fetchShoppingList: projectsStore.fetchShoppingList,
    fetchCostEstimation: projectsStore.fetchCostEstimation,
    fetchBpcKit: projectsStore.fetchBpcKit,
    fetchCopyCosts: projectsStore.fetchCopyCosts,
    analyzeMargin: projectsStore.analyzeMargin,
    clearError: projectsStore.clearError,

    // Steps store
    toggleStepPurchased: stepsStore.toggleStepPurchased,
    toggleStepInStock: stepsStore.toggleStepInStock,
    updateStepJobData: (projectId: string, stepId: string, data: Record<string, unknown>) =>
      stepsStore.updateStep(projectId, stepId, data),
    clearStepJobData: (projectId: string, stepId: string) =>
      stepsStore.updateStep(projectId, stepId, { clearJobData: true }),
    createStep: stepsStore.createStep,
    addChildJob: stepsStore.addChildJob,
    deleteStep: stepsStore.deleteStep,
    updateStepRuns: stepsStore.updateStepRuns,

    // Structures store - reactive state via getters
    get structures() { return structuresStore.structures },
    get rigOptions() { return structuresStore.rigOptions },
    get corporationStructures() { return structuresStore.corporationStructures },
    get userSettings() { return structuresStore.userSettings },
    fetchStructures: structuresStore.fetchStructures,
    fetchUserSettings: structuresStore.fetchUserSettings,
    updateUserSettings: structuresStore.updateUserSettings,
    fetchCorporationStructures: structuresStore.fetchCorporationStructures,
    createStructure: structuresStore.createStructure,
    updateStructure: structuresStore.updateStructure,
    deleteStructure: structuresStore.deleteStructure,
    searchStructures: structuresStore.searchStructures,

    // Blacklist store - reactive state via getter
    get blacklist() { return blacklistStore.blacklist },
    fetchBlacklist: blacklistStore.fetchBlacklist,
    updateBlacklist: blacklistStore.updateBlacklist,
    searchBlacklistItems: blacklistStore.searchBlacklistItems,

    // Cross-tab navigation intent
    get navigationIntent() { return navigationIntent.value },
    set navigationIntent(v: NavigationIntent) { navigationIntent.value = v },
  }
}
