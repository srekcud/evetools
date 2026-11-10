export interface NavItem {
  id: string
  labelKey: string
  icon: string
  route: string
  activePathPrefixes?: string[]
}

export interface NavGroup {
  id: string
  labelKey: string
  icon: string
  collapsible: true
  children: NavItem[]
}

export type NavEntry = NavItem | NavGroup

export function isNavGroup(entry: NavEntry): entry is NavGroup {
  return 'collapsible' in entry && entry.collapsible === true
}

export const allNavEntries: NavEntry[] = [
  { id: 'dashboard', labelKey: 'nav.dashboard', route: '/dashboard', icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6' },
  {
    id: 'production',
    labelKey: 'nav.production',
    icon: 'M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z',
    collapsible: true,
    children: [
      { id: 'industry', labelKey: 'nav.industry', route: '/industry', activePathPrefixes: ['/group-industry', '/industry/projects', '/industry/join/', '/join/'], icon: 'M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z' },
      { id: 'appraisal', labelKey: 'nav.appraisal', route: '/appraisal', icon: 'M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z' },
      { id: 'planetary', labelKey: 'nav.pi', route: '/planetary', icon: 'M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9' },
    ]
  },
  { id: 'mining', labelKey: 'nav.mining', route: '/mining/operations', activePathPrefixes: ['/mining/'], icon: 'M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9' },
  {
    id: 'economy',
    labelKey: 'nav.economy',
    icon: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
    collapsible: true,
    children: [
      { id: 'market', labelKey: 'nav.market', route: '/market', icon: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z' },
      { id: 'ledger', labelKey: 'nav.revenue', route: '/ledger', icon: 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6' },
      { id: 'assets', labelKey: 'nav.inventory', route: '/assets', icon: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4' },
    ]
  },
]

export const adminNavItem: NavItem = {
  id: 'admin', labelKey: 'nav.admin', route: '/admin', icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'
}

export const settableModules: NavItem[] = allNavEntries.flatMap(entry => isNavGroup(entry) ? entry.children : [entry])

export const LEGACY_GROUP_INDUSTRY_MODULE_ID = 'group-industry'

export const LEGACY_MINING_OPERATIONS_MODULE_ID = 'mining-operations'

const LEGACY_MODULE_IDS: Record<string, string> = {
  mining: LEGACY_MINING_OPERATIONS_MODULE_ID,
}

export function moduleIdsToUnhide(moduleId: string): string[] {
  const legacyId = LEGACY_MODULE_IDS[moduleId]
  return legacyId === undefined ? [moduleId] : [moduleId, legacyId]
}

export function isModuleHidden(hiddenModules: string[], moduleId: string): boolean {
  return moduleIdsToUnhide(moduleId).some(id => hiddenModules.includes(id))
}

export function isProjectsHidden(hiddenModules: string[]): boolean {
  return hiddenModules.includes('industry') || hiddenModules.includes(LEGACY_GROUP_INDUSTRY_MODULE_ID)
}

export function isNavItemActive(item: NavItem, currentPath: string): boolean {
  return currentPath === item.route || (item.activePathPrefixes ?? []).some(prefix => currentPath.startsWith(prefix))
}

export const HIDDEN_MODULES_STORAGE_KEY = 'evetools_hidden_modules'

export function readHiddenModules(): string[] {
  const stored = localStorage.getItem(HIDDEN_MODULES_STORAGE_KEY)
  if (!stored) {
    return []
  }
  try {
    const parsed: unknown = JSON.parse(stored)
    return Array.isArray(parsed) ? parsed.filter((moduleId): moduleId is string => typeof moduleId === 'string') : []
  } catch {
    return []
  }
}

export function saveHiddenModules(moduleIds: string[]): void {
  localStorage.setItem(HIDDEN_MODULES_STORAGE_KEY, JSON.stringify(moduleIds))
}
