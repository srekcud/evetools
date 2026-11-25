import type { PlannerCatalog, PlannerPrice, PlannerRecipe } from '@/utils/planetaryPlanner'
import type { AccountCharacter, PlannerAccount, PlannerExtractionPlanetTypes, PlannerPlanetType } from '@/utils/planetaryPlanets'

export const AQUEOUS_LIQUIDS = 91_001
export const BASE_METALS = 91_002
export const HEAVY_METALS = 91_003
export const WATER = 91_011
export const REACTIVE_METALS = 91_012
export const TOXIC_METALS = 91_013
export const COOLANT = 91_021
export const MECHANICAL_PARTS = 91_022
export const ROBOTICS = 91_031
export const BROADCAST_NODE = 91_041

export const ALMA_CHARACTER_ID = 90_000_101
export const BRAN_CHARACTER_ID = 90_000_102

export const LONG_TYPE_NAME = 'Coolant Reinforced Thermal Dissipation Compound of the Outer Rim Consortium, Extended Production Run'

export const PLANET_TYPES: PlannerPlanetType[] = [
  {
    name: 'Barren',
    commandCenter: { cpu: 25_415, pg: 19_000 },
    launchpad: { cpu: 3_600, pg: 700, capacity: 10_000 },
    facilities: { basic: { cpu: 200, pg: 800 }, advanced: { cpu: 500, pg: 700 }, highTech: { cpu: 1_100, pg: 400 } },
  },
  {
    name: 'Lava',
    commandCenter: { cpu: 21_000, pg: 15_000 },
    launchpad: { cpu: 3_600, pg: 700, capacity: 10_000 },
    facilities: { basic: { cpu: 200, pg: 800 }, advanced: { cpu: 500, pg: 700 }, highTech: null },
  },
  {
    name: 'Temperate',
    commandCenter: { cpu: 24_000, pg: 18_000 },
    launchpad: { cpu: 3_600, pg: 700, capacity: 10_000 },
    facilities: { basic: { cpu: 200, pg: 800 }, advanced: { cpu: 500, pg: 700 }, highTech: { cpu: 1_100, pg: 400 } },
  },
]

export const EXTRACTION_PLANET_TYPES: PlannerExtractionPlanetTypes[] = [
  { typeId: AQUEOUS_LIQUIDS, planetTypes: ['Barren', 'Temperate'] },
  { typeId: BASE_METALS, planetTypes: ['Barren', 'Lava', 'Temperate'] },
]

export const PLANNER_CATALOG: PlannerCatalog = {
  recipes: [
    { outputTypeId: WATER, outputQuantity: 20, cycleSeconds: 1800, inputs: [{ typeId: AQUEOUS_LIQUIDS, quantity: 3000 }] },
    { outputTypeId: REACTIVE_METALS, outputQuantity: 20, cycleSeconds: 1800, inputs: [{ typeId: BASE_METALS, quantity: 3000 }] },
    { outputTypeId: TOXIC_METALS, outputQuantity: 20, cycleSeconds: 1800, inputs: [{ typeId: HEAVY_METALS, quantity: 3000 }] },
    { outputTypeId: COOLANT, outputQuantity: 5, cycleSeconds: 3600, inputs: [{ typeId: WATER, quantity: 40 }, { typeId: REACTIVE_METALS, quantity: 40 }] },
    { outputTypeId: MECHANICAL_PARTS, outputQuantity: 5, cycleSeconds: 3600, inputs: [{ typeId: WATER, quantity: 40 }, { typeId: TOXIC_METALS, quantity: 40 }] },
    { outputTypeId: ROBOTICS, outputQuantity: 3, cycleSeconds: 3600, inputs: [{ typeId: COOLANT, quantity: 10 }, { typeId: MECHANICAL_PARTS, quantity: 10 }] },
    { outputTypeId: BROADCAST_NODE, outputQuantity: 1, cycleSeconds: 3600, inputs: [{ typeId: ROBOTICS, quantity: 6 }] },
  ],
  types: [
    { typeId: AQUEOUS_LIQUIDS, name: 'Aqueous Liquids', tier: 'P0', volume: 0.01 },
    { typeId: BASE_METALS, name: 'Base Metals', tier: 'P0', volume: 0.01 },
    { typeId: HEAVY_METALS, name: 'Heavy Metals', tier: 'P0', volume: null },
    { typeId: WATER, name: 'Water', tier: 'P1', volume: 0.38 },
    { typeId: REACTIVE_METALS, name: 'Reactive Metals', tier: 'P1', volume: 0.38 },
    { typeId: TOXIC_METALS, name: 'Toxic Metals', tier: 'P1', volume: 0.38 },
    { typeId: COOLANT, name: 'Coolant', tier: 'P2', volume: 1.5 },
    { typeId: MECHANICAL_PARTS, name: 'Mechanical Parts', tier: 'P2', volume: 1.5 },
    { typeId: ROBOTICS, name: 'Robotics', tier: 'P3', volume: 3 },
    { typeId: BROADCAST_NODE, name: 'Broadcast Node', tier: 'P4', volume: 100 },
  ],
  prices: [
    { typeId: AQUEOUS_LIQUIDS, sell: 6, buy: 5 },
    { typeId: BASE_METALS, sell: 7, buy: 6 },
    { typeId: HEAVY_METALS, sell: 8, buy: 7 },
    { typeId: WATER, sell: 60, buy: 50 },
    { typeId: REACTIVE_METALS, sell: 140, buy: 120 },
    { typeId: TOXIC_METALS, sell: 75, buy: 70 },
    { typeId: COOLANT, sell: 1000, buy: 900 },
    { typeId: MECHANICAL_PARTS, sell: 2200, buy: 2000 },
    { typeId: ROBOTICS, sell: 30000, buy: 28000 },
    { typeId: BROADCAST_NODE, sell: 900000, buy: 850000 },
  ],
  planetTypes: PLANET_TYPES,
  extractionPlanetTypes: EXTRACTION_PLANET_TYPES,
}

export const PLANNER_ACCOUNT: PlannerAccount = {
  usedPlanets: 4,
  characters: [
    { characterId: ALMA_CHARACTER_ID, characterName: 'Alma Voss', usedPlanets: 3, maxPlanets: 6 },
    { characterId: BRAN_CHARACTER_ID, characterName: 'Bran Tolk', usedPlanets: 1, maxPlanets: 4 },
  ],
  extractionYields: [
    { typeId: AQUEOUS_LIQUIDS, perHourPerPlanet: 6_000, planets: 2 },
    { typeId: BASE_METALS, perHourPerPlanet: 5_000, planets: 3 },
  ],
}

export function withSellPrices(catalog: PlannerCatalog, sellByTypeId: Record<number, number | null>): PlannerCatalog {
  return {
    ...catalog,
    prices: catalog.prices.map((price: PlannerPrice) => (price.typeId in sellByTypeId ? { ...price, sell: sellByTypeId[price.typeId] as number | null } : price)),
  }
}

export function withTypeName(catalog: PlannerCatalog, typeId: number, name: string): PlannerCatalog {
  return { ...catalog, types: catalog.types.map(type => (type.typeId === typeId ? { ...type, name } : type)) }
}

export function withRecipe(catalog: PlannerCatalog, outputTypeId: number, changes: Partial<Omit<PlannerRecipe, 'outputTypeId'>>): PlannerCatalog {
  return { ...catalog, recipes: catalog.recipes.map(recipe => (recipe.outputTypeId === outputTypeId ? { ...recipe, ...changes } : recipe)) }
}

export function withTypeVolume(catalog: PlannerCatalog, typeId: number, volume: number | null): PlannerCatalog {
  return { ...catalog, types: catalog.types.map(type => (type.typeId === typeId ? { ...type, volume } : type)) }
}

export function withMaxPlanets(account: PlannerAccount, maxPlanetsByCharacterId: Record<number, number | null>): PlannerAccount {
  return {
    ...account,
    characters: account.characters.map((character: AccountCharacter) => (character.characterId in maxPlanetsByCharacterId ? { ...character, maxPlanets: maxPlanetsByCharacterId[character.characterId] as number | null } : character)),
  }
}
