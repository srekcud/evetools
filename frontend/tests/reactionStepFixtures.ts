import type { ReactionStep } from '@/stores/group-industry/types'

export const FULLERIDES_ID = 345_001
export const HYPERSYNAPTIC_FIBERS_ID = 345_002
export const CAESARIUM_CADMIDE_ID = 345_003
export const THULIUM_HAFNITE_ID = 345_004

export const FULLERIDES: ReactionStep = {
  typeId: FULLERIDES_ID,
  typeName: 'Fullerides',
  runs: 1,
  producedQuantity: 3_000,
  surplusQuantity: 2_509,
  neededQuantity: 491,
  outputPerRun: 3_000,
  baseSecondsPerRun: 10_800,
  baseTotalSeconds: 10_800,
  inputs: [
    { typeId: 345_101, typeName: 'Carbon Polymers', quantityPerRun: 100, totalQuantity: 100, isFuelBlock: false },
    { typeId: 345_102, typeName: 'Platinum Technite', quantityPerRun: 100, totalQuantity: 100, isFuelBlock: false },
    { typeId: 345_103, typeName: 'Nitrogen Fuel Block', quantityPerRun: 5, totalQuantity: 5, isFuelBlock: true },
  ],
}

export const HYPERSYNAPTIC_FIBERS: ReactionStep = {
  typeId: HYPERSYNAPTIC_FIBERS_ID,
  typeName: 'Hypersynaptic Fibers',
  runs: 5,
  producedQuantity: 1_000,
  surplusQuantity: 0,
  neededQuantity: 1_000,
  outputPerRun: 200,
  baseSecondsPerRun: 10_800,
  baseTotalSeconds: 54_000,
  inputs: [
    { typeId: 345_104, typeName: 'Silicon Dioxide', quantityPerRun: 60, totalQuantity: 300, isFuelBlock: false },
    { typeId: 345_105, typeName: 'Hydrogen Fuel Block', quantityPerRun: 3, totalQuantity: 15, isFuelBlock: true },
  ],
}

export const CAESARIUM_CADMIDE: ReactionStep = {
  typeId: CAESARIUM_CADMIDE_ID,
  typeName: 'Caesarium Cadmide',
  runs: 2,
  producedQuantity: 600,
  surplusQuantity: null,
  neededQuantity: null,
  outputPerRun: 300,
  baseSecondsPerRun: 3_600,
  baseTotalSeconds: 7_200,
  inputs: [
    { typeId: 345_106, typeName: 'Cadmium', quantityPerRun: 40, totalQuantity: 80, isFuelBlock: false },
  ],
}

export const THULIUM_HAFNITE: ReactionStep = {
  typeId: THULIUM_HAFNITE_ID,
  typeName: 'Thulium Hafnite',
  runs: 4,
  producedQuantity: 400,
  surplusQuantity: 120,
  neededQuantity: 280,
  outputPerRun: null,
  baseSecondsPerRun: null,
  baseTotalSeconds: null,
  inputs: null,
}

export function reactionStep(overrides: Partial<ReactionStep>): ReactionStep {
  return { ...FULLERIDES, ...overrides }
}
