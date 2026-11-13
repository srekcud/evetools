/**
 * Backward-compatible re-exports from the split industry stores.
 *
 * Existing code importing from '@/stores/industry' will continue to work.
 * New code should import directly from '@/stores/industry/projects', etc.
 */
import { ref } from 'vue'
import { useProjectsStore } from './industry/projects'
import { useStructuresStore } from './industry/structures'
import { useBlacklistStore } from './industry/blacklist'
import type { NavigationIntent } from './industry/types'

// Module-level ref shared across all useIndustryStore() calls
const navigationIntent = ref<NavigationIntent>(null)

// Re-export all types for backward compatibility
export type {
  SearchResult,
  BlacklistCategory,
  BlacklistItem,
  BlacklistConfig,
  StructureConfig,
  StructureSearchResult,
  RigOption,
  RigOptions,
  StructureBonusPreview,
  CorporationStructureSharedConfig,
  CorporationStructure,
  CharacterSkill,
  UserSettings,
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

/**
 * Composite store that combines all industry sub-stores.
 * This provides backward compatibility with the old monolithic useIndustryStore.
 *
 * Uses getters/setters to maintain reactivity on state properties.
 */
export function useIndustryStore() {
  const projectsStore = useProjectsStore()
  const structuresStore = useStructuresStore()
  const blacklistStore = useBlacklistStore()

  return {
    // Projects store - reactive state via getters/setters
    get searchResults() { return projectsStore.searchResults },
    set searchResults(v) { projectsStore.searchResults = v },
    get error() { return projectsStore.error },
    set error(v) { projectsStore.error = v },
    get marginResult() { return projectsStore.marginResult },
    get marginLoading() { return projectsStore.marginLoading },
    get marginError() { return projectsStore.marginError },
    get defaultMaxJobDurationDays() { return projectsStore.defaultMaxJobDurationDays },
    setDefaultMaxJobDurationDays: projectsStore.setDefaultMaxJobDurationDays,
    searchProducts: projectsStore.searchProducts,
    fetchManufacturableType: projectsStore.fetchManufacturableType,
    analyzeMargin: projectsStore.analyzeMargin,
    clearError: projectsStore.clearError,

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
    previewStructureBonuses: structuresStore.previewStructureBonuses,

    // Blacklist store - reactive state via getter
    get blacklist() { return blacklistStore.blacklist },
    fetchBlacklist: blacklistStore.fetchBlacklist,
    updateBlacklist: blacklistStore.updateBlacklist,
    updateExcludeInvention: blacklistStore.updateExcludeInvention,
    searchBlacklistItems: blacklistStore.searchBlacklistItems,

    // Cross-tab navigation intent
    get navigationIntent() { return navigationIntent.value },
    set navigationIntent(v: NavigationIntent) { navigationIntent.value = v },
  }
}
