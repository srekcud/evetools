import type { ChainNode } from '@/stores/group-industry/types'

export function chainNode(overrides: Partial<ChainNode>): ChainNode {
  return {
    id: 'node',
    stage: 'material',
    parentId: null,
    typeId: 0,
    typeName: '',
    activity: null,
    runs: null,
    meLevel: null,
    teLevel: null,
    requiredQuantity: 0,
    fulfilledQuantity: 0,
    status: 'todo',
    costState: 'none',
    realCost: null,
    suppliers: [],
    jobs: [],
    structures: [],
    plannedStructure: null,
    soldQuantity: null,
    grossRevenue: null,
    ...overrides,
  }
}

export const HAMMERHEAD_II_ID = 'node-hammerhead-ii'
export const HAMMERHEAD_I_ID = 'node-hammerhead-i'
export const HAMMERHEAD_I_COPY_ID = 'node-hammerhead-i-copy'
export const HAMMERHEAD_II_INVENTION_ID = 'node-hammerhead-ii-invention'
export const TRITANIUM_ID = 'node-tritanium'
export const PARTICLE_ACCELERATOR_UNIT_ID = 'node-particle-accelerator-unit'
export const SALE_ID = 'sale'

export const HAMMERHEAD_II = chainNode({
  id: HAMMERHEAD_II_ID,
  stage: 'product',
  typeId: 2_205,
  typeName: 'Hammerhead II',
  activity: 'manufacturing',
  runs: 2,
  meLevel: 2,
  teLevel: 4,
  requiredQuantity: 2,
  status: 'blocked',
})

export const HAMMERHEAD_I = chainNode({
  id: HAMMERHEAD_I_ID,
  stage: 'component',
  parentId: HAMMERHEAD_II_ID,
  typeId: 2_183,
  typeName: 'Hammerhead I',
  activity: 'manufacturing',
  runs: 50,
  meLevel: 10,
  teLevel: 20,
  requiredQuantity: 50,
  status: 'done',
  costState: 'known',
  realCost: 214_000,
  suppliers: [{ participantId: 'participant-wenzel', characterName: 'Wenzel Roth', type: 'job_install', quantity: 50 }],
  jobs: [
    { esiJobId: 910_001, status: 'delivered', runs: 25, characterName: 'Wenzel Roth', endDate: '2026-11-02T09:10:00+00:00', cost: 100_000, facilityName: 'Raitaru Alpha' },
    { esiJobId: 910_002, status: 'delivered', runs: 25, characterName: 'Wenzel Roth', endDate: '2026-11-03T14:30:00+00:00', cost: 114_000, facilityName: 'Raitaru Alpha' },
  ],
  structures: ['Raitaru Alpha'],
  plannedStructure: { name: 'Azbel Beta', status: 'best_in_favorite_system' },
})

export const HAMMERHEAD_I_COPY = chainNode({
  id: HAMMERHEAD_I_COPY_ID,
  stage: 'blueprint',
  parentId: HAMMERHEAD_I_ID,
  typeId: 2_184,
  typeName: 'Hammerhead I Blueprint',
  activity: 'copy',
  runs: 15,
  requiredQuantity: 15,
  status: 'done',
})

export const HAMMERHEAD_II_INVENTION = chainNode({
  id: HAMMERHEAD_II_INVENTION_ID,
  stage: 'blueprint',
  parentId: HAMMERHEAD_II_ID,
  typeId: 2_206,
  typeName: 'Hammerhead II Blueprint',
  activity: 'invention',
  runs: 15,
  requiredQuantity: 15,
  status: 'todo',
})

export const TRITANIUM = chainNode({
  id: TRITANIUM_ID,
  stage: 'material',
  typeId: 34,
  typeName: 'Tritanium',
  requiredQuantity: 1_000,
  fulfilledQuantity: 400,
  status: 'active',
})

export const PARTICLE_ACCELERATOR_UNIT = chainNode({
  id: PARTICLE_ACCELERATOR_UNIT_ID,
  stage: 'material',
  typeId: 11_530,
  typeName: 'Particle Accelerator Unit',
  requiredQuantity: 100,
  fulfilledQuantity: 100,
  status: 'done',
  costState: 'unknown',
  suppliers: [
    { participantId: 'participant-ulla', characterName: 'Ulla Voss', type: 'material', quantity: 60 },
    { participantId: 'participant-varek', characterName: 'Varek Dunn', type: 'material', quantity: 40 },
  ],
})

export const SALE = chainNode({
  id: SALE_ID,
  stage: 'sale',
  status: 'active',
  soldQuantity: 5,
  grossRevenue: 3_600_000,
})

export const PRODUCTION_CHAIN: ChainNode[] = [
  HAMMERHEAD_II_INVENTION,
  HAMMERHEAD_I_COPY,
  TRITANIUM,
  PARTICLE_ACCELERATOR_UNIT,
  HAMMERHEAD_I,
  HAMMERHEAD_II,
  SALE,
]
