import type { PlannerCatalog, PlannerPrice, PlannerRecipe } from '@/utils/planetaryPlanner'

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

export const LONG_TYPE_NAME = 'Coolant Reinforced Thermal Dissipation Compound of the Outer Rim Consortium, Extended Production Run'

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
