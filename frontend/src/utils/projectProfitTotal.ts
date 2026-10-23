import type { IndustryProject } from '@/stores/industry/types'

export interface ProjectProfitTotal {
  total: number
  unknownProfitCount: number
}

export function projectProfitTotal(projects: readonly Pick<IndustryProject, 'profit' | 'personalUse'>[]): ProjectProfitTotal {
  const counted = projects.filter(project => !project.personalUse)
  const known = counted.filter(project => project.profit !== null)
  return {
    total: known.reduce((sum, project) => sum + (project.profit as number), 0),
    unknownProfitCount: counted.length - known.length,
  }
}
