import { useI18n } from 'vue-i18n'
import { adminNavItem, allNavEntries, isNavGroup, isProjectsHidden, readHiddenModules, type NavItem } from '@/constants/sidebarNavigation'

export interface NavigationEntry {
  route: string
  label: string
  aliasLabel: string
  isTab: boolean
}

export interface NavigationEntriesOptions {
  isAdmin: boolean
}

interface ScreenTab {
  query: string
  labelKey: string
}

const INDUSTRY_MODULE = 'industry'
const ALIAS_LOCALE = 'en'
const LABEL_SEPARATOR = ' › '

type Screen = Pick<NavItem, 'labelKey' | 'route'>

const CHARACTERS_SCREEN: Screen = {
  labelKey: 'nav.characters',
  route: '/characters',
}

const PROJECTS_SCREEN: Screen = {
  labelKey: 'nav.projects',
  route: '/group-industry',
}

const PROJECTS_TABS: ScreenTab[] = [
  { query: 'available', labelKey: 'groupIndustry.tabs.available' },
]

const TABS_BY_MODULE: Record<string, ScreenTab[]> = {
  ledger: [
    { query: 'pve', labelKey: 'ledger.tabs.pve' },
    { query: 'mining', labelKey: 'ledger.tabs.mining' },
    { query: 'escalations', labelKey: 'ledger.tabs.escalations' },
    { query: 'settings', labelKey: 'ledger.tabs.settings' },
  ],
  industry: [
    { query: 'margins', labelKey: 'industry.tabs.margins' },
    { query: 'batch', labelKey: 'industry.tabs.batch' },
    { query: 'buy-vs-build', labelKey: 'industry.tabs.buyVsBuild' },
    { query: 'pivot', labelKey: 'industry.tabs.pivot' },
    { query: 'slots', labelKey: 'industry.tabs.slots' },
    { query: 'stockpile', labelKey: 'industry.tabs.stockpile' },
    { query: 'blueprints', labelKey: 'industry.tabs.blueprints' },
  ],
  'mining-operations': [
    { query: 'available', labelKey: 'miningOperation.tabs.available' },
  ],
  market: [
    { query: 'alerts', labelKey: 'market.tabs.alerts' },
  ],
  assets: [
    { query: 'contracts', labelKey: 'assets.tabs.contracts' },
  ],
}

const SIDEBAR_SCREENS: NavItem[] = allNavEntries.flatMap(entry => isNavGroup(entry) ? entry.children : [entry])

export function useNavigationEntries(): {
  listNavigationEntries: (options: NavigationEntriesOptions) => NavigationEntry[]
} {
  const { t } = useI18n()

  function labelInInterfaceLanguage(labelKey: string): string {
    return t(labelKey)
  }

  function labelInAliasLanguage(labelKey: string): string {
    return t(labelKey, {}, { locale: ALIAS_LOCALE })
  }

  function screenEntry(screen: Screen): NavigationEntry {
    return {
      route: screen.route,
      label: labelInInterfaceLanguage(screen.labelKey),
      aliasLabel: labelInAliasLanguage(screen.labelKey),
      isTab: false,
    }
  }

  function tabEntry(screen: Screen, tab: ScreenTab, parent?: Screen): NavigationEntry {
    const labelKeys = [...(parent ? [parent.labelKey] : []), screen.labelKey, tab.labelKey]
    return {
      route: `${screen.route}?tab=${tab.query}`,
      label: labelKeys.map(labelInInterfaceLanguage).join(LABEL_SEPARATOR),
      aliasLabel: labelKeys.map(labelInAliasLanguage).join(LABEL_SEPARATOR),
      isTab: true,
    }
  }

  function projectEntries(industry: Screen): NavigationEntry[] {
    return [
      {
        route: PROJECTS_SCREEN.route,
        label: [industry.labelKey, PROJECTS_SCREEN.labelKey].map(labelInInterfaceLanguage).join(LABEL_SEPARATOR),
        aliasLabel: [industry.labelKey, PROJECTS_SCREEN.labelKey].map(labelInAliasLanguage).join(LABEL_SEPARATOR),
        isTab: false,
      },
      ...PROJECTS_TABS.map(tab => tabEntry(PROJECTS_SCREEN, tab, industry)),
    ]
  }

  function listNavigationEntries({ isAdmin }: NavigationEntriesOptions): NavigationEntry[] {
    const hiddenModules = readHiddenModules()
    const entries = SIDEBAR_SCREENS
      .filter(screen => !hiddenModules.includes(screen.id))
      .flatMap(screen => [
        screenEntry(screen),
        ...(TABS_BY_MODULE[screen.id] ?? []).map(tab => tabEntry(screen, tab)),
        ...(screen.id === INDUSTRY_MODULE && !isProjectsHidden(hiddenModules) ? projectEntries(screen) : []),
      ])
    entries.push(screenEntry(CHARACTERS_SCREEN))
    if (isAdmin) {
      entries.push(screenEntry(adminNavItem))
    }
    return entries
  }

  return { listNavigationEntries }
}
