import type { BomItem } from '@/stores/group-industry/types'

export type UnfinishedLines = {
  uncoveredMaterials: BomItem[]
  undeliveredJobs: BomItem[]
}

export function unfinishedLinesOf(lines: readonly BomItem[]): UnfinishedLines {
  const open = lines.filter(line => !line.isFulfilled)
  return {
    uncoveredMaterials: open.filter(line => !line.isJob),
    undeliveredJobs: open.filter(line => line.isJob),
  }
}
