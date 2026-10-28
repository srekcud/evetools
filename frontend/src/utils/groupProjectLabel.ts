import type { GroupProject } from '@/stores/group-industry/types'

export function groupProjectLabel(project: Pick<GroupProject, 'name' | 'items'>): string {
  if (project.name) {
    return project.name
  }
  if (project.items.length === 1) {
    const [item] = project.items
    return `${item.runs}x ${item.typeName}`
  }
  return `${project.items.length} items`
}
