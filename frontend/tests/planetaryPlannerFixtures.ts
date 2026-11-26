import type { PlannerCatalog, PlannerPrice, PlannerRecipe } from '@/utils/planetaryPlanner'
import type { AccountCharacter, PlannerAccount, PlannerExtractionPlanetTypes, PlannerPlanetType } from '@/utils/planetaryPlanets'
import type { PlannerMarket, PlannerMarketProduct } from '@/utils/planetaryRanking'

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

export const RANKING_INPUT_A = 92_011
export const RANKING_INPUT_C = 92_012
export const RANKING_PRODUCT_B = 92_021
export const RANKING_FIRST_GENERATED_PRODUCT = 93_001

export const THE_FORGE_REGION_ID = 10_000_002

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
  factories: { P1: 0, P2: 0, P3: 0, P4: 0 },
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

export function withPlanetTypes(catalog: PlannerCatalog, planetTypes: PlannerPlanetType[]): PlannerCatalog {
  return { ...catalog, planetTypes }
}

export function withExtractionYield(account: PlannerAccount, typeId: number, perHourPerPlanet: number): PlannerAccount {
  return {
    ...account,
    extractionYields: account.extractionYields.map(accountYield => (accountYield.typeId === typeId ? { ...accountYield, perHourPerPlanet } : accountYield)),
  }
}

const DISTINCT_INPUT_VOLUMES: Record<number, number> = { [WATER]: 0.5, [REACTIVE_METALS]: 0.2, [TOXIC_METALS]: 0.3, [COOLANT]: 2.5, [BROADCAST_NODE]: 85 }

const DISTINCT_INPUT_RECIPES: Record<number, Partial<Omit<PlannerRecipe, 'outputTypeId'>>> = {
  [COOLANT]: { outputQuantity: 4, inputs: [{ typeId: WATER, quantity: 30 }, { typeId: REACTIVE_METALS, quantity: 70 }] },
  [MECHANICAL_PARTS]: { outputQuantity: 6, inputs: [{ typeId: WATER, quantity: 20 }, { typeId: TOXIC_METALS, quantity: 90 }] },
  [ROBOTICS]: { inputs: [{ typeId: COOLANT, quantity: 10 }, { typeId: MECHANICAL_PARTS, quantity: 15 }] },
}

export const DISTINCT_INPUTS_CATALOG: PlannerCatalog = Object.entries(DISTINCT_INPUT_RECIPES).reduce(
  (catalog, [outputTypeId, changes]) => withRecipe(catalog, Number(outputTypeId), changes),
  Object.entries(DISTINCT_INPUT_VOLUMES).reduce((catalog, [typeId, volume]) => withTypeVolume(catalog, Number(typeId), volume), PLANNER_CATALOG),
)

export const PLANNER_MARKET: PlannerMarket = {
  regionId: THE_FORGE_REGION_ID,
  products: [
    { typeId: COOLANT, averageDailyVolume: 12_000, averagePrice: 1_010, priceChange: 4.2, lastDate: '2026-11-20' },
    { typeId: MECHANICAL_PARTS, averageDailyVolume: 3_400, averagePrice: 2_190, priceChange: -1.5, lastDate: '2026-11-21' },
    { typeId: ROBOTICS, averageDailyVolume: 460, averagePrice: 29_800, priceChange: 8.8, lastDate: '2026-11-22' },
  ],
}

export const RANKING_PLANET_TYPE: PlannerPlanetType = {
  name: 'Barren',
  commandCenter: { cpu: 25_415, pg: 19_000 },
  launchpad: { cpu: 3_600, pg: 700, capacity: 10_000 },
  facilities: { basic: null, advanced: { cpu: 500, pg: 700 }, highTech: null },
}

export const RANKING_CATALOG: PlannerCatalog = {
  recipes: [
    { outputTypeId: RANKING_PRODUCT_B, outputQuantity: 5, cycleSeconds: 3_600, inputs: [{ typeId: RANKING_INPUT_A, quantity: 40 }, { typeId: RANKING_INPUT_C, quantity: 40 }] },
  ],
  types: [
    { typeId: RANKING_INPUT_A, name: 'Ranking Input A', tier: 'P1', volume: 0.01 },
    { typeId: RANKING_INPUT_C, name: 'Ranking Input C', tier: 'P1', volume: 0.01 },
    { typeId: RANKING_PRODUCT_B, name: 'Ranking Product B', tier: 'P2', volume: 0.01 },
  ],
  prices: [
    { typeId: RANKING_INPUT_A, sell: 20, buy: 18 },
    { typeId: RANKING_INPUT_C, sell: 30, buy: 27 },
    { typeId: RANKING_PRODUCT_B, sell: 1_000, buy: 900 },
  ],
  planetTypes: [RANKING_PLANET_TYPE],
  extractionPlanetTypes: [],
}

export const RANKING_MARKET: PlannerMarket = {
  regionId: THE_FORGE_REGION_ID,
  products: [{ typeId: RANKING_PRODUCT_B, averageDailyVolume: 20_000, averagePrice: 977.5, priceChange: 3.5, lastDate: '2026-11-25' }],
}

export function catalogWithP2Products(count: number): PlannerCatalog {
  const generated = Array.from({ length: count }, (_, position) => RANKING_FIRST_GENERATED_PRODUCT + position)
  return {
    ...RANKING_CATALOG,
    recipes: generated.map((typeId, position) => ({
      outputTypeId: typeId,
      outputQuantity: 2 + (position % 3),
      cycleSeconds: 3_600,
      inputs: [{ typeId: RANKING_INPUT_A, quantity: 10 + position }, { typeId: RANKING_INPUT_C, quantity: 20 + position }],
    })),
    types: [
      ...RANKING_CATALOG.types.filter(type => type.tier === 'P1'),
      ...generated.map((typeId, position) => ({ typeId, name: `Ranking Product ${position + 1}`, tier: 'P2' as const, volume: 0.01 })),
    ],
    prices: [
      ...RANKING_CATALOG.prices.filter(price => price.typeId === RANKING_INPUT_A || price.typeId === RANKING_INPUT_C),
      ...generated.map((typeId, position) => ({ typeId, sell: 500 + 37 * position, buy: 450 + 31 * position })),
    ],
  }
}

export function withMarketProduct(market: PlannerMarket, typeId: number, changes: Partial<Omit<PlannerMarketProduct, 'typeId'>>): PlannerMarket {
  return { ...market, products: market.products.map(product => (product.typeId === typeId ? { ...product, ...changes } : product)) }
}

export function withoutMarketProduct(market: PlannerMarket, typeId: number): PlannerMarket {
  return { ...market, products: market.products.filter(product => product.typeId !== typeId) }
}

export const DISTINCT_OUTPUTS_CATALOG: PlannerCatalog = withSellPrices(
  withRecipe(
    withRecipe(
      withRecipe(PLANNER_CATALOG, COOLANT, { outputQuantity: 4 }),
      MECHANICAL_PARTS,
      { outputQuantity: 6 },
    ),
    ROBOTICS,
    { inputs: [{ typeId: COOLANT, quantity: 10 }, { typeId: MECHANICAL_PARTS, quantity: 20 }] },
  ),
  { [COOLANT]: 3_000 },
)
