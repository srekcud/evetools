import { factoriesOfTier, type PlanetaryTier, type PlannerCatalog, type TreeNode } from '@/utils/planetaryPlanner'
import type { FactoryTier, PlanetFacility, PlannerPlanetType } from '@/utils/planetaryPlanets'

export const MAX_LAUNCHPADS = 6
export const FACILITY_BY_TIER: Record<FactoryTier, 'advanced' | 'highTech'> = { P2: 'advanced', P3: 'advanced', P4: 'highTech' }

const CHAIN_TIERS: readonly FactoryTier[] = ['P2', 'P3', 'P4']
const FIRST_COUNT = 1

export type ChainFactories = Partial<Record<FactoryTier, number>>

export interface ChainLayout {
  launchpads: number
  factoriesPerPlanet: ChainFactories
  dailyVolume: number
  capacity: number
  days: number
}

export interface ChainPlanetRow {
  role: 'chain'
  tiers: FactoryTier[]
  planetTypes: string[]
  factoriesByTier: ChainFactories
  layout: ChainLayout | null
  status: 'ok' | 'unknownVolume' | 'doesNotFit'
  planets: number | null
}

export interface ChainContext {
  catalog: PlannerCatalog
  days: number
  volumeOf: (typeId: number) => number | null
}

interface TierFactories {
  tier: FactoryTier
  factories: number
}

interface ChainFit {
  planets: number
  launchpads: number
  factoriesPerPlanet: TierFactories[]
}

function isChainTier(tier: PlanetaryTier): tier is FactoryTier {
  return CHAIN_TIERS.some(chainTier => chainTier === tier)
}

function isMadeInChain(node: TreeNode): boolean {
  return node.factories !== null && isChainTier(node.tier)
}

function volumeMovedPerDay(node: TreeNode, volumeOf: ChainContext['volumeOf']): number | null {
  const volume = volumeOf(node.typeId)
  return volume === null ? null : node.perDay * volume
}

function chainVolumePerDay(trees: TreeNode[], nodes: TreeNode[], volumeOf: ChainContext['volumeOf']): number | null {
  const importedNodes = nodes.filter(isMadeInChain).flatMap(node => node.children.filter(child => !isMadeInChain(child)))
  const exportedNodes = trees.filter(isMadeInChain)
  const volumes = [...importedNodes, ...exportedNodes].map(node => volumeMovedPerDay(node, volumeOf))
  if (volumes.some(volume => volume === null)) return null
  return (volumes as number[]).reduce((total, volume) => total + volume, 0)
}

function facilityOf(planetType: PlannerPlanetType, tier: FactoryTier): PlanetFacility {
  const facility = planetType.facilities[FACILITY_BY_TIER[tier]]
  if (facility === null) throw new Error(`Planet type ${planetType.name} has no ${FACILITY_BY_TIER[tier]} installation for ${tier}`)
  return facility
}

function hostsEveryTier(planetType: PlannerPlanetType, tiers: FactoryTier[]): boolean {
  return tiers.every(tier => planetType.facilities[FACILITY_BY_TIER[tier]] !== null)
}

function installationLoad(planetType: PlannerPlanetType, factoriesPerPlanet: TierFactories[], dimension: 'cpu' | 'pg'): number {
  return factoriesPerPlanet.reduce((load, { tier, factories }) => load + factories * facilityOf(planetType, tier)[dimension], 0)
}

function fitsWithLaunchpads(planetType: PlannerPlanetType, factoriesPerPlanet: TierFactories[], launchpads: number, volumePerPlanet: number, days: number): boolean {
  const { commandCenter, launchpad } = planetType
  return installationLoad(planetType, factoriesPerPlanet, 'cpu') + launchpads * launchpad.cpu <= commandCenter.cpu
    && installationLoad(planetType, factoriesPerPlanet, 'pg') + launchpads * launchpad.pg <= commandCenter.pg
    && volumePerPlanet * days <= launchpads * launchpad.capacity
}

function fitOn(planetType: PlannerPlanetType, tierFactories: TierFactories[], dailyVolume: number, days: number): ChainFit | null {
  const largestTier = Math.max(...tierFactories.map(({ factories }) => factories))
  const planetBound = Math.max(largestTier, Math.ceil((dailyVolume * days) / planetType.launchpad.capacity))

  for (let planets = FIRST_COUNT; planets <= planetBound; planets++) {
    const factoriesPerPlanet = tierFactories.map(({ tier, factories }) => ({ tier, factories: Math.ceil(factories / planets) }))
    for (let launchpads = FIRST_COUNT; launchpads <= MAX_LAUNCHPADS; launchpads++) {
      if (fitsWithLaunchpads(planetType, factoriesPerPlanet, launchpads, dailyVolume / planets, days)) return { planets, launchpads, factoriesPerPlanet }
    }
  }
  return null
}

function factoriesRecord(tierFactories: TierFactories[]): ChainFactories {
  return Object.fromEntries(tierFactories.map(({ tier, factories }) => [tier, factories])) as ChainFactories
}

export function chainRowOf(trees: TreeNode[], nodes: TreeNode[], context: ChainContext): ChainPlanetRow | null {
  const tierFactories = CHAIN_TIERS.flatMap(tier => {
    const factories = factoriesOfTier(nodes, tier)
    return factories === 0 ? [] : [{ tier, factories }]
  })
  if (tierFactories.length === 0) return null

  const tiers = tierFactories.map(({ tier }) => tier)
  const factoriesByTier = factoriesRecord(tierFactories)
  const hosts = context.catalog.planetTypes.filter(planetType => hostsEveryTier(planetType, tiers))
  const hostNames = hosts.map(host => host.name)
  const dailyVolume = chainVolumePerDay(trees, nodes, context.volumeOf)

  if (dailyVolume === null) return { role: 'chain', tiers, planetTypes: hostNames, factoriesByTier, layout: null, status: 'unknownVolume', planets: null }

  const fits = hosts.flatMap(planetType => {
    const fit = fitOn(planetType, tierFactories, dailyVolume, context.days)
    return fit === null ? [] : [{ planetType, fit }]
  })
  if (fits.length === 0) return { role: 'chain', tiers, planetTypes: hostNames, factoriesByTier, layout: null, status: 'doesNotFit', planets: null }

  const widest = fits.reduce((best, candidate) => (candidate.fit.planets > best.fit.planets ? candidate : best))
  const { planets, launchpads, factoriesPerPlanet } = widest.fit
  return {
    role: 'chain',
    tiers,
    planetTypes: fits.map(({ planetType }) => planetType.name),
    factoriesByTier,
    layout: {
      launchpads,
      factoriesPerPlanet: factoriesRecord(factoriesPerPlanet),
      dailyVolume,
      capacity: launchpads * widest.planetType.launchpad.capacity,
      days: context.days,
    },
    status: 'ok',
    planets,
  }
}
