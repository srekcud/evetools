import {
  FACTORY_TOLERANCE,
  SECONDS_PER_DAY,
  activeNodes,
  buildPlan,
  extractionPerHourOf,
  factoriesOfTier,
  indexCatalog,
  type BuildChoice,
  type CatalogIndex,
  type PlannedProduction,
  type PlannerCatalog,
  type PlannerSettings,
  type TreeNode,
} from '@/utils/planetaryPlanner'

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

const FACTORY_TIERS_FROM_TOP: readonly FactoryTier[] = ['P4', 'P3', 'P2']

interface PlanetContext {
  catalog: PlannerCatalog
  index: CatalogIndex
  days: number
  account: PlannerAccount | null
  overrides: Record<number, number>
}

function volumeOf(typeId: number, index: CatalogIndex): number | null {
  const type = index.typeById.get(typeId)
  if (!type) throw new Error(`Type ${typeId} is missing from the planner catalog`)
  return type.volume
}

function cycleVolumePerDay(node: TreeNode, index: CatalogIndex): number | null {
  const recipe = index.recipeByOutput.get(node.typeId)
  if (!recipe) throw new Error(`Type ${node.typeId} has no recipe in the planner catalog`)

  const outputVolume = volumeOf(node.typeId, index)
  if (outputVolume === null) return null
  let inputsVolume = 0
  for (const input of recipe.inputs) {
    const inputVolume = volumeOf(input.typeId, index)
    if (inputVolume === null) return null
    inputsVolume += input.quantity * inputVolume
  }
  return (Math.max(inputsVolume, recipe.outputQuantity * outputVolume) * SECONDS_PER_DAY) / recipe.cycleSeconds
}

function dailyVolumeOfNodes(tier: FactoryTier, nodes: TreeNode[], index: CatalogIndex): number | null {
  const volumes = nodes.filter(node => node.tier === tier && node.factories !== null).map(node => cycleVolumePerDay(node, index))
  if (volumes.length === 0 || volumes.some(volume => volume === null)) return null
  return Math.max(...(volumes as number[]))
}

export function factoryDailyVolume(tier: FactoryTier, trees: TreeNode[], catalog: PlannerCatalog): number | null {
  return dailyVolumeOfNodes(tier, trees.flatMap(activeNodes), indexCatalog(catalog))
}

function layoutWithLaunchpads(planetType: PlannerPlanetType, facility: PlanetFacility, launchpads: number, dailyVolume: number, days: number): PlanetLayout {
  const { commandCenter, launchpad } = planetType
  const capacity = launchpads * launchpad.capacity
  const byCpu = Math.floor((commandCenter.cpu - launchpads * launchpad.cpu) / facility.cpu)
  const byPower = Math.floor((commandCenter.pg - launchpads * launchpad.pg) / facility.pg)
  const byVolume = Math.floor(capacity / (dailyVolume * days))
  return { launchpads, factories: Math.max(0, Math.min(byCpu, byPower, byVolume)), dailyVolume, capacity, days }
}

export function bestLayout(planetType: PlannerPlanetType, facility: PlanetFacility, dailyVolume: number, days: number): PlanetLayout {
  const layouts = Array.from({ length: MAX_LAUNCHPADS }, (_, position) => layoutWithLaunchpads(planetType, facility, position + 1, dailyVolume, days))
  return layouts.reduce((best, candidate) => (candidate.factories > best.factories ? candidate : best))
}

function hostingPlanetTypes(tier: FactoryTier, catalog: PlannerCatalog): { planetType: PlannerPlanetType, facility: PlanetFacility }[] {
  return catalog.planetTypes.flatMap(planetType => {
    const facility = planetType.facilities[FACILITY_BY_TIER[tier]]
    return facility === null ? [] : [{ planetType, facility }]
  })
}

export function factoryLayout(tier: FactoryTier, catalog: PlannerCatalog, dailyVolume: number, days: number): { planetTypes: string[], layout: PlanetLayout | null } {
  const hosts = hostingPlanetTypes(tier, catalog)
  const layouts = hosts.map(host => bestLayout(host.planetType, host.facility, dailyVolume, days))
  const fewest = layouts.reduce<PlanetLayout | null>((best, candidate) => (best === null || candidate.factories < best.factories ? candidate : best), null)
  return { planetTypes: hosts.map(host => host.planetType.name), layout: fewest }
}

export function planetsForFactories(factories: number, perPlanet: number): number {
  return Math.ceil(factories / perPlanet)
}

export function extractionPlanets(perHour: number, yieldPerHour: number): number {
  return Math.ceil(perHour / yieldPerHour - FACTORY_TOLERANCE)
}

export function charactersNeeded(totalPlanets: number): number {
  return Math.ceil(totalPlanets / PLANETS_PER_CHARACTER)
}

export function accountMaxPlanets(account: PlannerAccount | null): number | null {
  if (account === null || account.characters.length === 0) return null
  let total = 0
  for (const character of account.characters) {
    if (character.maxPlanets === null) return null
    total += character.maxPlanets
  }
  return total
}

export function isValidExtractionYield(value: number): boolean {
  return Number.isFinite(value) && value > 0
}

function factoryRowOf(tier: FactoryTier, factoriesNeeded: number, nodes: TreeNode[], context: PlanetContext): FactoryPlanetRow {
  const dailyVolume = dailyVolumeOfNodes(tier, nodes, context.index)
  const { planetTypes, layout } = dailyVolume === null
    ? { planetTypes: hostingPlanetTypes(tier, context.catalog).map(host => host.planetType.name), layout: null }
    : factoryLayout(tier, context.catalog, dailyVolume, context.days)

  if (layout === null) return { role: 'factory', tier, planetTypes, factoriesNeeded, layout: null, status: 'unknownVolume', planets: null }
  if (layout.factories === 0) return { role: 'factory', tier, planetTypes, factoriesNeeded, layout, status: 'doesNotFit', planets: null }
  return { role: 'factory', tier, planetTypes, factoriesNeeded, layout, status: 'ok', planets: planetsForFactories(factoriesNeeded, layout.factories) }
}

function p1FactoriesByResource(nodes: TreeNode[]): Map<number, number> {
  const fractional = new Map<number, number>()
  for (const node of nodes) {
    if (node.tier !== 'P1' || node.factories === null) continue
    for (const child of node.children.filter(input => input.tier === 'P0')) {
      const already = fractional.get(child.typeId)
      fractional.set(child.typeId, already === undefined ? node.factories : already + node.factories)
    }
  }
  return new Map([...fractional].map(([typeId, factories]) => [typeId, Math.max(0, Math.ceil(factories - FACTORY_TOLERANCE))]))
}

function extractionRowsOf(nodes: TreeNode[], context: PlanetContext): ExtractionPlanetRow[] {
  const p1Factories = p1FactoriesByResource(nodes)

  return extractionPerHourOf(nodes)
    .sort((left, right) => left.typeId - right.typeId)
    .map(({ typeId, perHour }) => {
      const typed = context.overrides[typeId] as number | undefined
      const accountEntry = context.account?.extractionYields.find(accountYield => accountYield.typeId === typeId)
      const known = accountEntry !== undefined && isValidExtractionYield(accountEntry.perHourPerPlanet) ? accountEntry : undefined
      const yieldPerHour = typed ?? known?.perHourPerPlanet ?? null
      const yieldSource = typed !== undefined ? 'override' : known ? 'account' : null
      const feedingFactories = p1Factories.get(typeId)
      if (feedingFactories === undefined) throw new Error(`Raw resource ${typeId} feeds no P1 factory of the plan`)
      return {
        role: 'extraction' as const,
        typeId,
        planetTypes: context.catalog.extractionPlanetTypes.find(entry => entry.typeId === typeId)?.planetTypes ?? [],
        perHour,
        yieldPerHour,
        yieldSource,
        p1Factories: feedingFactories,
        planets: yieldPerHour === null ? null : extractionPlanets(perHour, yieldPerHour),
      }
    })
}

function planetRowsOf(nodes: TreeNode[], context: PlanetContext): (FactoryPlanetRow | ExtractionPlanetRow)[] {
  const factoryRows = FACTORY_TIERS_FROM_TOP.flatMap(tier => {
    const factoriesNeeded = factoriesOfTier(nodes, tier)
    return factoriesNeeded === 0 ? [] : [factoryRowOf(tier, factoriesNeeded, nodes, context)]
  })
  return [...factoryRows, ...extractionRowsOf(nodes, context)]
}

function totalPlanetsOf(rows: (FactoryPlanetRow | ExtractionPlanetRow)[]): number {
  return rows.reduce((total, row) => total + (row.planets === null ? 0 : row.planets), 0)
}

function isIncomplete(rows: (FactoryPlanetRow | ExtractionPlanetRow)[]): boolean {
  return rows.some(row => row.planets === null)
}

function openSlotsOf(row: FactoryPlanetRow | undefined): number {
  if (row === undefined || row.planets === null || row.layout === null) return 0
  return row.planets * row.layout.factories - row.factoriesNeeded
}

function allocationsOf(rows: (FactoryPlanetRow | ExtractionPlanetRow)[], previousRows: (FactoryPlanetRow | ExtractionPlanetRow)[]): TierAllocation[] {
  const factoryRows = (list: (FactoryPlanetRow | ExtractionPlanetRow)[]) => list.filter((row): row is FactoryPlanetRow => row.role === 'factory')
  const previousByTier = new Map(factoryRows(previousRows).map(row => [row.tier, row]))

  return factoryRows(rows).flatMap(row => {
    const previous = previousByTier.get(row.tier)
    const added = row.factoriesNeeded - (previous ? previous.factoriesNeeded : 0)
    if (row.status !== 'ok' || row.layout === null || added <= 0) return []
    const onFreeSlots = Math.min(openSlotsOf(previous), added)
    return [{ tier: row.tier, onFreeSlots, onNewPlanets: added - onFreeSlots, freeRemaining: openSlotsOf(row) }]
  })
}

export function buildPlanetPlan(
  productions: PlannedProduction[],
  catalog: PlannerCatalog,
  choices: Record<number, BuildChoice>,
  settings: PlannerSettings,
  account: PlannerAccount | null,
  overrides: Record<number, number>,
): PlanetPlan {
  const context: PlanetContext = { catalog, index: indexCatalog(catalog), days: settings.daysBetweenRounds, account, overrides }
  const { trees } = buildPlan(productions, catalog, choices, settings)

  let nodes: TreeNode[] = []
  const rowsAfterEachProduction = trees.map(tree => {
    nodes = [...nodes, ...activeNodes(tree)]
    return planetRowsOf(nodes, context)
  })
  const rows = rowsAfterEachProduction.length === 0 ? [] : rowsAfterEachProduction[rowsAfterEachProduction.length - 1]
  const totalsAfterEachProduction = rowsAfterEachProduction.map(totalPlanetsOf)
  const totalPlanets = totalPlanetsOf(rows)
  const accountMax = accountMaxPlanets(account)

  return {
    rows,
    totalPlanets,
    incomplete: isIncomplete(rows),
    charactersNeeded: charactersNeeded(totalPlanets),
    usedPlanets: account === null ? null : account.usedPlanets,
    accountMaxPlanets: accountMax,
    freePlanets: accountMax === null ? null : Math.max(0, accountMax - totalPlanets),
    addedByProduction: totalsAfterEachProduction.map((total, position) => total - (position === 0 ? 0 : totalsAfterEachProduction[position - 1])),
    allocations: rowsAfterEachProduction.map((productionRows, position) => allocationsOf(productionRows, position === 0 ? [] : rowsAfterEachProduction[position - 1])),
  }
}
