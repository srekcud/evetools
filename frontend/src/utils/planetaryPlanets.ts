import type { BuildChoice, PlannedProduction, PlannerCatalog, PlannerSettings, TreeNode } from '@/utils/planetaryPlanner'

export interface PlanetFacility {
  cpu: number
  pg: number
}

export interface PlannerPlanetType {
  name: string
  commandCenter: PlanetFacility
  launchpad: PlanetFacility & { capacity: number }
  facilities: { basic: PlanetFacility | null, advanced: PlanetFacility | null, highTech: PlanetFacility | null }
}

export interface PlannerExtractionPlanetTypes {
  typeId: number
  planetTypes: string[]
}

export interface AccountCharacter {
  characterId: number
  characterName: string
  usedPlanets: number
  maxPlanets: number | null
}

export interface ExtractionYield {
  typeId: number
  perHourPerPlanet: number
  planets: number
}

export interface PlannerAccount {
  usedPlanets: number
  characters: AccountCharacter[]
  extractionYields: ExtractionYield[]
}

export type FactoryTier = 'P2' | 'P3' | 'P4'

export interface PlanetLayout {
  launchpads: number
  factories: number
  dailyVolume: number
  capacity: number
  days: number
}

export interface FactoryPlanetRow {
  role: 'factory'
  tier: FactoryTier
  planetTypes: string[]
  factoriesNeeded: number
  layout: PlanetLayout | null
  status: 'ok' | 'unknownVolume' | 'doesNotFit'
  planets: number | null
}

export interface ExtractionPlanetRow {
  role: 'extraction'
  typeId: number
  planetTypes: string[]
  perHour: number
  yieldPerHour: number | null
  yieldSource: 'override' | 'account' | null
  p1Factories: number
  planets: number | null
}

export interface TierAllocation {
  tier: FactoryTier
  onFreeSlots: number
  onNewPlanets: number
  freeRemaining: number
}

export interface PlanetPlan {
  rows: (FactoryPlanetRow | ExtractionPlanetRow)[]
  totalPlanets: number
  incomplete: boolean
  charactersNeeded: number
  usedPlanets: number | null
  accountMaxPlanets: number | null
  freePlanets: number | null
  addedByProduction: number[]
  allocations: TierAllocation[][]
}

export const MAX_LAUNCHPADS = 6
export const PLANETS_PER_CHARACTER = 6
export const FACILITY_BY_TIER: Record<FactoryTier, 'advanced' | 'highTech'> = { P2: 'advanced', P3: 'advanced', P4: 'highTech' }
export const SECONDS_PER_HOUR = 3600

export function factoryDailyVolume(tier: FactoryTier, trees: TreeNode[], catalog: PlannerCatalog): number | null {
  void tier
  void trees
  void catalog
  return null
}

export function bestLayout(planetType: PlannerPlanetType, facility: PlanetFacility, dailyVolume: number, days: number): PlanetLayout {
  void planetType
  void facility
  return { launchpads: 0, factories: 0, dailyVolume, capacity: 0, days }
}

export function factoryLayout(tier: FactoryTier, catalog: PlannerCatalog, dailyVolume: number, days: number): { planetTypes: string[], layout: PlanetLayout | null } {
  void tier
  void catalog
  void dailyVolume
  void days
  return { planetTypes: [], layout: null }
}

export function planetsForFactories(factories: number, perPlanet: number): number {
  void factories
  void perPlanet
  return 0
}

export function extractionPlanets(perHour: number, yieldPerHour: number): number {
  void perHour
  void yieldPerHour
  return 0
}

export function charactersNeeded(totalPlanets: number): number {
  void totalPlanets
  return 0
}

export function accountMaxPlanets(account: PlannerAccount | null): number | null {
  void account
  return null
}

export function buildPlanetPlan(
  productions: PlannedProduction[],
  catalog: PlannerCatalog,
  choices: Record<number, BuildChoice>,
  settings: PlannerSettings,
  account: PlannerAccount | null,
  overrides: Record<number, number>,
): PlanetPlan {
  void catalog
  void choices
  void settings
  void account
  void overrides
  return {
    rows: [],
    totalPlanets: 0,
    incomplete: false,
    charactersNeeded: 0,
    usedPlanets: null,
    accountMaxPlanets: null,
    freePlanets: null,
    addedByProduction: productions.map(() => 0),
    allocations: productions.map(() => []),
  }
}

export function isValidExtractionYield(value: number): boolean {
  void value
  return true
}
