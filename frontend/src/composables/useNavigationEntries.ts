import { useI18n } from 'vue-i18n'
import { adminNavItem, allNavEntries, isModuleHidden, isProjectsHidden, readHiddenModules, type NavModule, type NavPage } from '@/constants/sidebarNavigation'
import { SETTINGS_PATH, SETTINGS_SECTIONS, settingsSectionLabelKey } from '@/constants/settingsSections'

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

const ALIAS_LOCALE = 'en'
const LABEL_SEPARATOR = ' › '
const PROJECTS_PAGE_ROUTE = '/industry/projects'

const TABS_BY_PAGE_ROUTE: Record<string, ScreenTab[]> = {
  '/industry/projects': [
    { query: 'available', labelKey: 'groupIndustry.tabs.available' },
  ],
  '/industry/analysis': [
    { query: 'margins', labelKey: 'industry.tabs.margins' },
    { query: 'batch', labelKey: 'industry.tabs.batch' },
    { query: 'buy-vs-build', labelKey: 'industry.tabs.buyVsBuild' },
    { query: 'pivot', labelKey: 'industry.tabs.pivot' },
  ],
  '/industry/production': [
    { query: 'slots', labelKey: 'industry.tabs.slots' },
    { query: 'stockpile', labelKey: 'industry.tabs.stockpile' },
    { query: 'blueprints', labelKey: 'industry.tabs.blueprints' },
  ],
  '/mining/operations': [
    { query: 'available', labelKey: 'miningOperation.tabs.available' },
    { query: 'archive', labelKey: 'miningOperation.tabs.archive' },
  ],
  '/mining/moons': [
    { query: 'tax', labelKey: 'nav.miningMoonTax' },
  ],
  '/mining/my-mining': [
    { query: 'season', labelKey: 'miningOperation.tabs.season' },
  ],
  '/market': [
    { query: 'alerts', labelKey: 'market.tabs.alerts' },
  ],
}

export function useNavigationEntries(): {
  listNavigationEntries: (options: NavigationEntriesOptions) => NavigationEntry[]
} {
  const { t } = useI18n()

  function entryOf(route: string, labelKeys: string[], isTab: boolean): NavigationEntry {
    return {
      route,
      label: labelKeys.map(labelKey => t(labelKey)).join(LABEL_SEPARATOR),
      aliasLabel: labelKeys.map(labelKey => t(labelKey, {}, { locale: ALIAS_LOCALE })).join(LABEL_SEPARATOR),
      isTab,
    }
  }

  function tabEntries(page: NavPage, parentLabelKeys: string[]): NavigationEntry[] {
    return (TABS_BY_PAGE_ROUTE[page.route] ?? []).map(tab => entryOf(`${page.route}?tab=${tab.query}`, [...parentLabelKeys, tab.labelKey], true))
  }

  function pageEntries(module: NavModule, page: NavPage): NavigationEntry[] {
    if (page.route === module.route) {
      return [entryOf(module.route, [module.labelKey], false), ...tabEntries(page, [module.labelKey])]
    }
    return [entryOf(page.route, [module.labelKey, page.labelKey], page.route !== PROJECTS_PAGE_ROUTE), ...tabEntries(page, [module.labelKey, page.labelKey])]
  }

  function settingsSectionEntries(module: NavModule, settingsPage: NavPage): NavigationEntry[] {
    return SETTINGS_SECTIONS.map(section => entryOf(`${SETTINGS_PATH}/${section}`, [module.labelKey, settingsPage.labelKey, settingsSectionLabelKey(section)], true))
  }

  function moduleEntries(module: NavModule, hiddenModules: string[]): NavigationEntry[] {
    if (module.pages.length === 0) {
      return [entryOf(module.route, [module.labelKey], false)]
    }
    return module.pages
      .filter(page => page.route !== PROJECTS_PAGE_ROUTE || !isProjectsHidden(hiddenModules))
      .flatMap(page => [
        ...pageEntries(module, page),
        ...(page.route === SETTINGS_PATH ? settingsSectionEntries(module, page) : []),
      ])
  }

  function listNavigationEntries({ isAdmin }: NavigationEntriesOptions): NavigationEntry[] {
    const hiddenModules = readHiddenModules()
    const entries = allNavEntries
      .filter(module => !isModuleHidden(hiddenModules, module.id))
      .flatMap(module => moduleEntries(module, hiddenModules))
    if (isAdmin) {
      entries.push(entryOf(adminNavItem.route, [adminNavItem.labelKey], false))
    }
    return entries
  }

  return { listNavigationEntries }
}
