import { ACTIVITY_GROUP_ORDER, type ActivityGroup } from './companionTasks'

export const COMPANION_COLLAPSED_STORAGE_KEY = 'groupCompanion.collapsed'

export type CompanionPane = 'free' | 'mine' | 'transport' | 'jobs'

export type CompanionSectionKey = `${CompanionPane}:${ActivityGroup}`

export const COMPANION_PANES: CompanionPane[] = ['free', 'mine', 'jobs']

const SECTION_KEYS: CompanionSectionKey[] = COMPANION_PANES.flatMap(pane => ACTIVITY_GROUP_ORDER.map((group): CompanionSectionKey => `${pane}:${group}`))

function isSectionKey(value: unknown): value is CompanionSectionKey {
  return typeof value === 'string' && SECTION_KEYS.some(key => key === value)
}

export function recallCollapsedSections(): CompanionSectionKey[] {
  try {
    const stored = localStorage.getItem(COMPANION_COLLAPSED_STORAGE_KEY)
    if (stored === null) return []
    const parsed: unknown = JSON.parse(stored)
    return Array.isArray(parsed) ? parsed.filter(isSectionKey) : []
  } catch {
    return []
  }
}

export function rememberCollapsedSections(collapsed: CompanionSectionKey[]): void {
  try {
    localStorage.setItem(COMPANION_COLLAPSED_STORAGE_KEY, JSON.stringify(collapsed))
  } catch {
    return
  }
}
