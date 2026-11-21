import type { GroupProject } from '@/stores/group-industry/types'

export function projectDisplayNameOf(project: Pick<GroupProject, 'name' | 'items'>): string {
  if (project.name) return project.name
  return project.items.map(item => `${item.runs}x ${item.typeName}`).join(', ')
}
