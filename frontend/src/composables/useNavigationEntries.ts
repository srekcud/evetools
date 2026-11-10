import { useI18n } from 'vue-i18n'
import { adminNavItem, allNavEntries, isModuleHidden, isNavGroup, isProjectsHidden, readHiddenModules, type NavItem } from '@/constants/sidebarNavigation'

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
const MARKET_MODULE = 'market'
const ALIAS_LOCALE = 'en'
const LABEL_SEPARATOR = ' › '

type Screen = Pick<NavItem, 'labelKey' | 'route'>

const CHARACTERS_SCREEN: Screen = {
  labelKey: 'nav.characters',
  route: '/characters',
}

const PROJECTS_SCREEN: Screen = {
  labelKey: 'nav.projects',
  route: '/industry/projects',
}

const PROJECTS_TABS: ScreenTab[] = [
  { query: 'available', labelKey: 'groupIndustry.tabs.available' },
]

interface ModulePage {
  route: string
  labelKey: string
  tabs: ScreenTab[]
}

const INDUSTRY_PAGES: ModulePage[] = [
  {
    route: '/industry/analysis',
    labelKey: 'industryModule.tabs.analysis',
    tabs: [
      { query: 'margins', labelKey: 'industry.tabs.margins' },
      { query: 'batch', labelKey: 'industry.tabs.batch' },
      { query: 'buy-vs-build', labelKey: 'industry.tabs.buyVsBuild' },
      { query: 'pivot', labelKey: 'industry.tabs.pivot' },
    ],
  },
  {
    route: '/industry/production',
    labelKey: 'industryModule.tabs.production',
    tabs: [
      { query: 'slots', labelKey: 'industry.tabs.slots' },
      { query: 'stockpile', labelKey: 'industry.tabs.stockpile' },
      { query: 'blueprints', labelKey: 'industry.tabs.blueprints' },
    ],
  },
]

const PAGES_BY_MODULE: Record<string, ModulePage[]> = {
  mining: [
    { route: '/mining/moons', labelKey: 'nav.miningMoons', tabs: [{ query: 'tax', labelKey: 'nav.miningMoonTax' }] },
    { route: '/mining/sites', labelKey: 'miningOperation.tabs.sites', tabs: [] },
    { route: '/mining/ore-value', labelKey: 'nav.miningOreValue', tabs: [] },
    { route: '/mining/my-mining', labelKey: 'nav.miningMyMining', tabs: [{ query: 'season', labelKey: 'miningOperation.tabs.season' }] },
  ],
  pve: [
    { route: '/pve/escalations', labelKey: 'ledger.tabs.escalations', tabs: [] },
  ],
}

const COMMERCE_SCREEN: Screen = {
  labelKey: 'nav.commerce',
  route: '/contracts',
}

const CONTRACTS_LABEL_KEY = 'commerce.tabs.contracts'

const TABS_BY_MODULE: Record<string, ScreenTab[]> = {
  ledger: [
    { query: 'industry', labelKey: 'ledger.tabs.industry' },
    { query: 'settings', labelKey: 'ledger.tabs.settings' },
  ],
  mining: [
    { query: 'available', labelKey: 'miningOperation.tabs.available' },
    { query: 'archive', labelKey: 'miningOperation.tabs.archive' },
  ],
  market: [
    { query: 'alerts', labelKey: 'market.tabs.alerts' },
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

  function modulePageEntries(module: Screen, pages: ModulePage[]): NavigationEntry[] {
    return pages.flatMap((page) => {
      const labelKeys = [module.labelKey, page.labelKey]
      return [
        {
          route: page.route,
          label: labelKeys.map(labelInInterfaceLanguage).join(LABEL_SEPARATOR),
          aliasLabel: labelKeys.map(labelInAliasLanguage).join(LABEL_SEPARATOR),
          isTab: true,
        },
        ...page.tabs.map(tab => tabEntry(page, tab, module)),
      ]
    })
  }

  function contractsEntry(): NavigationEntry {
    const labelKeys = [COMMERCE_SCREEN.labelKey, CONTRACTS_LABEL_KEY]
    return {
      route: COMMERCE_SCREEN.route,
      label: labelKeys.map(labelInInterfaceLanguage).join(LABEL_SEPARATOR),
      aliasLabel: labelKeys.map(labelInAliasLanguage).join(LABEL_SEPARATOR),
      isTab: true,
    }
  }

  function listNavigationEntries({ isAdmin }: NavigationEntriesOptions): NavigationEntry[] {
    const hiddenModules = readHiddenModules()
    const entries = SIDEBAR_SCREENS
      .filter(screen => !isModuleHidden(hiddenModules, screen.id))
      .flatMap(screen => [
        screenEntry(screen),
        ...(TABS_BY_MODULE[screen.id] ?? []).map(tab => tabEntry(screen, tab)),
        ...modulePageEntries(screen, PAGES_BY_MODULE[screen.id] ?? []),
        ...(screen.id === MARKET_MODULE ? [contractsEntry()] : []),
        ...(screen.id === INDUSTRY_MODULE && !isProjectsHidden(hiddenModules) ? projectEntries(screen) : []),
        ...(screen.id === INDUSTRY_MODULE ? modulePageEntries(screen, INDUSTRY_PAGES) : []),
      ])
    entries.push(screenEntry(CHARACTERS_SCREEN))
    if (isAdmin) {
      entries.push(screenEntry(adminNavItem))
    }
    return entries
  }

  return { listNavigationEntries }
}
