/**
 * Industry module stores - barrel exports.
 */
export { useProjectsStore } from './projects'
export { useStepsStore } from './steps'
export { useStructuresStore } from './structures'
export { useBlacklistStore } from './blacklist'
export { usePurchasesStore } from './purchases'
export { enrichStep, enrichProject, formatErrorMessage } from './compat'

// Re-export all types
export type {
  RootProduct,
  JobMatch,
  StepPurchase,
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
  CharacterSkill,
  UserSettings,
  PurchaseSuggestion,
  AvailableJob,
} from './types'
