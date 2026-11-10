export interface NavItem {
  id: string
  labelKey: string
  icon: string
  route: string
  activePathPrefixes?: string[]
}

export interface NavPage {
  route: string
  labelKey: string
}

export interface NavModule extends NavItem {
  pages: NavPage[]
}

export const allNavEntries: NavModule[] = [
  { id: 'dashboard', labelKey: 'nav.dashboard', route: '/dashboard', icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6', pages: [] },
  {
    id: 'industry',
    labelKey: 'nav.industry',
    route: '/industry',
    activePathPrefixes: ['/industry/', '/join/'],
    icon: 'M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z',
    pages: [
      { route: '/industry', labelKey: 'industryModule.tabs.workshop' },
      { route: '/industry/projects', labelKey: 'industryModule.tabs.projects' },
      { route: '/industry/analysis', labelKey: 'industryModule.tabs.analysis' },
      { route: '/industry/production', labelKey: 'industryModule.tabs.production' },
    ],
  },
  {
    id: 'mining',
    labelKey: 'nav.mining',
    route: '/mining/operations',
    activePathPrefixes: ['/mining/', '/mining-operations', '/mining-sites'],
    icon: 'M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
    pages: [
      { route: '/mining/operations', labelKey: 'miningModule.tabs.operations' },
      { route: '/mining/moons', labelKey: 'miningModule.tabs.moons' },
      { route: '/mining/sites', labelKey: 'miningModule.tabs.sites' },
      { route: '/mining/ore-value', labelKey: 'miningModule.tabs.oreValue' },
      { route: '/mining/my-mining', labelKey: 'miningModule.tabs.myMining' },
    ],
  },
  {
    id: 'pve',
    labelKey: 'nav.pve',
    route: '/pve/revenue',
    activePathPrefixes: ['/pve/'],
    icon: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    pages: [
      { route: '/pve/revenue', labelKey: 'nav.revenue' },
      { route: '/pve/escalations', labelKey: 'pveModule.tabs.escalations' },
    ],
  },
  { id: 'planetary', labelKey: 'nav.planetary', route: '/planetary', icon: 'M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9', pages: [] },
  {
    id: 'commerce',
    labelKey: 'nav.commerce',
    route: '/market',
    icon: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
    pages: [
      { route: '/market', labelKey: 'commerce.tabs.market' },
      { route: '/appraisal', labelKey: 'commerce.tabs.appraisal' },
      { route: '/contracts', labelKey: 'commerce.tabs.contracts' },
    ],
  },
  {
    id: 'patrimoine',
    labelKey: 'nav.patrimoine',
    route: '/balance',
    icon: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
    pages: [
      { route: '/balance', labelKey: 'nav.balance' },
      { route: '/assets', labelKey: 'nav.inventory' },
    ],
  },
  {
    id: 'account',
    labelKey: 'nav.account',
    route: '/characters',
    activePathPrefixes: ['/settings/'],
    icon: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
    pages: [
      { route: '/characters', labelKey: 'nav.characters' },
      { route: '/settings', labelKey: 'settings.title' },
    ],
  },
]

export const adminNavItem: NavItem = {
  id: 'admin', labelKey: 'nav.admin', route: '/admin', icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'
}

export const ACCOUNT_MODULE_ID = 'account'

export const settableModules: NavModule[] = allNavEntries.filter(module => module.id !== ACCOUNT_MODULE_ID)

export const LEGACY_GROUP_INDUSTRY_MODULE_ID = 'group-industry'

export const LEGACY_MINING_OPERATIONS_MODULE_ID = 'mining-operations'

const LEGACY_MODULE_IDS: Record<string, string[]> = {
  mining: [LEGACY_MINING_OPERATIONS_MODULE_ID],
  commerce: ['market', 'appraisal'],
  patrimoine: ['ledger', 'assets'],
}

export function moduleIdsToUnhide(moduleId: string): string[] {
  return [moduleId, ...(LEGACY_MODULE_IDS[moduleId] ?? [])]
}

export function isModuleHidden(hiddenModules: string[], moduleId: string): boolean {
  if (moduleId === ACCOUNT_MODULE_ID) {
    return false
  }
  const legacyIds = LEGACY_MODULE_IDS[moduleId] ?? []
  return hiddenModules.includes(moduleId) || (legacyIds.length > 0 && legacyIds.every(id => hiddenModules.includes(id)))
}

export function isProjectsHidden(hiddenModules: string[]): boolean {
  return hiddenModules.includes('industry') || hiddenModules.includes(LEGACY_GROUP_INDUSTRY_MODULE_ID)
}

export function isNavItemActive(item: NavItem | NavModule, currentPath: string): boolean {
  const pageRoutes = 'pages' in item ? item.pages.map(page => page.route) : []
  return currentPath === item.route
    || pageRoutes.includes(currentPath)
    || (item.activePathPrefixes ?? []).some(prefix => currentPath.startsWith(prefix))
}

export function pageOfPath(module: NavModule, currentPath: string): NavPage | undefined {
  const isListedUnder = (page: NavPage) => module.pages.some(other => other.route.startsWith(`${page.route}/`))
  return module.pages
    .filter(page => currentPath === page.route || (!isListedUnder(page) && currentPath.startsWith(`${page.route}/`)))
    .sort((first, second) => second.route.length - first.route.length)[0]
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
