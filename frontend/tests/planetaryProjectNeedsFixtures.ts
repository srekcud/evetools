import type { ProjectNeedProduct } from '@/stores/planetaryProjectNeeds'

export const ISHTAR_FLEET_ID = '0193a000-0000-7000-8000-00000000139a'
export const RATTLESNAKE_BATCH_ID = '0193a000-0000-7000-8000-00000000139b'
export const SLEEPER_BATCH_ID = '0193a000-0000-7000-8000-00000000139c'

export const BROADCAST_NODE_NEED: ProjectNeedProduct = {
  typeId: 91_071,
  typeName: 'Broadcast Node',
  required: 11,
  stock: 7,
  missing: 4,
  projects: [
    { projectId: ISHTAR_FLEET_ID, projectName: 'Ishtar fleet', remaining: 6 },
    { projectId: RATTLESNAKE_BATCH_ID, projectName: 'Rattlesnake batch', remaining: 5 },
  ],
}

export const ROBOTICS_NEED: ProjectNeedProduct = {
  typeId: 91_072,
  typeName: 'Robotics',
  required: 1200,
  stock: 1200,
  missing: 0,
  projects: [{ projectId: ISHTAR_FLEET_ID, projectName: 'Ishtar fleet', remaining: 1200 }],
}

export const UNKNOWN_STOCK_NEED: ProjectNeedProduct = {
  typeId: 91_073,
  typeName: 'Coolant',
  required: 8,
  stock: null,
  missing: null,
  projects: [{ projectId: SLEEPER_BATCH_ID, projectName: 'Sleeper batch', remaining: 8 }],
}

export function projectNeedsOf(products: ProjectNeedProduct[]): { id: string; products: ProjectNeedProduct[] } {
  return { id: 'project-needs', products }
}

export const PROJECT_NEEDS_WITH_THREE_PRODUCTS = projectNeedsOf([BROADCAST_NODE_NEED, ROBOTICS_NEED, UNKNOWN_STOCK_NEED])
