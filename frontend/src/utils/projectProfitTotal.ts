import type { IndustryProject } from '@/stores/industry/types'

export interface ProjectProfitTotal {
  total: number
  unknownProfitCount: number
}

export function projectProfitTotal(_projects: readonly Pick<IndustryProject, 'profit' | 'personalUse'>[]): ProjectProfitTotal {
  throw new Error('Not implemented yet.')
}
