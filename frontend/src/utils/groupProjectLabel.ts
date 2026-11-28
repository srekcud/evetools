import type { GroupProject } from '@/stores/group-industry/types'

const QUANTITY_LOCALE = 'en-US'

export function groupProjectLabel(project: Pick<GroupProject, 'name' | 'items'>): string {
  if (project.name) {
    return project.name
  }
  const runsByType = new Map<number, { typeName: string; runs: number }>()
  for (const item of project.items) {
    const total = runsByType.get(item.typeId)
    if (total === undefined) {
      runsByType.set(item.typeId, { typeName: item.typeName, runs: item.runs })
    } else {
      total.runs += item.runs
    }
  }
  const [first, ...others] = [...runsByType.values()]
  if (first === undefined) {
    return ''
  }
  const label = `${first.runs.toLocaleString(QUANTITY_LOCALE)}x ${first.typeName}`
  return others.length === 0 ? label : `${label} +${others.length}`
}
