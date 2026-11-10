import { createApp, type App } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { useNavigationEntries, type NavigationEntry } from '@/composables/useNavigationEntries'
import { allNavEntries } from '@/constants/sidebarNavigation'

type Locale = 'en' | 'fr'

const HIDDEN_MODULES_STORAGE_KEY = 'evetools_hidden_modules'

const mountedApps: App[] = []

function mountNavigationEntries(locale: Locale): ReturnType<typeof useNavigationEntries> {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  let navigationEntries!: ReturnType<typeof useNavigationEntries>
  const app = createApp({
    setup: () => {
      navigationEntries = useNavigationEntries()
      return () => null
    },
  })
  app.use(i18n)
  app.mount(document.createElement('div'))
  mountedApps.push(app)
  return navigationEntries
}

function hideModules(moduleIds: string[]): void {
  localStorage.setItem(HIDDEN_MODULES_STORAGE_KEY, JSON.stringify(moduleIds))
}

function routesOf(entries: NavigationEntry[]): string[] {
  return entries.map(entry => entry.route)
}

const SETTINGS_SECTION_ROUTES = [
  '/settings/account',
  '/settings/display',
  '/settings/market',
  '/settings/industry',
  '/settings/mining',
  '/settings/pve',
  '/settings/notifications',
]

function withoutSettingsEntries(entries: NavigationEntry[]): NavigationEntry[] {
  return entries.filter(entry => !entry.route.startsWith('/settings'))
}

function withoutSettingsSections(routes: string[]): string[] {
  return routes.filter(route => !route.startsWith('/settings/'))
}

beforeEach(() => {
  localStorage.clear()
})

afterEach(() => {
  mountedApps.splice(0).forEach(app => app.unmount())
  localStorage.clear()
})

describe('useNavigationEntries', () => {
  it('lists every module followed by its pages and their non default tabs, in registry order, with English labels', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    expect(withoutSettingsEntries(listNavigationEntries({ isAdmin: false })).map(entry => [entry.route, entry.label])).toEqual([
      ['/dashboard', 'Home'],
      ['/industry', 'Industry'],
      ['/industry/projects', 'Industry › Projects'],
      ['/industry/projects?tab=available', 'Industry › Projects › Available'],
      ['/industry/analysis', 'Industry › Analysis'],
      ['/industry/analysis?tab=margins', 'Industry › Analysis › Margins'],
      ['/industry/analysis?tab=batch', 'Industry › Analysis › Batch Scan'],
      ['/industry/analysis?tab=buy-vs-build', 'Industry › Analysis › Buy vs Build'],
      ['/industry/analysis?tab=pivot', 'Industry › Analysis › Pivot'],
      ['/industry/production', 'Industry › Production'],
      ['/industry/production?tab=slots', 'Industry › Production › Slots'],
      ['/industry/production?tab=stockpile', 'Industry › Production › Stockpile'],
      ['/industry/production?tab=blueprints', 'Industry › Production › Blueprints'],
      ['/mining/operations', 'Mining'],
      ['/mining/operations?tab=available', 'Mining › Available'],
      ['/mining/operations?tab=archive', 'Mining › Archive'],
      ['/mining/moons', 'Mining › Moons'],
      ['/mining/moons?tab=tax', 'Mining › Moons › Tax'],
      ['/mining/sites', 'Mining › Sites'],
      ['/mining/ore-value', 'Mining › Ore value'],
      ['/mining/my-mining', 'Mining › My mining'],
      ['/mining/my-mining?tab=season', 'Mining › My mining › Season'],
      ['/pve/revenue', 'PVE'],
      ['/pve/escalations', 'PVE › Escalations'],
      ['/planetary', 'Planetary'],
      ['/market', 'Commerce'],
      ['/market?tab=alerts', 'Commerce › Alerts'],
      ['/appraisal', 'Commerce › Appraisal'],
      ['/contracts', 'Commerce › Contracts'],
      ['/balance', 'Wealth'],
      ['/assets', 'Wealth › Inventory'],
      ['/characters', 'Account'],
    ])
  })

  it('marks the module entries as screens and the ?tab= entries as tabs', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    const entries = listNavigationEntries({ isAdmin: false })
    const moduleRoutes = allNavEntries.map(module => module.route)

    expect(entries.filter(entry => moduleRoutes.includes(entry.route)).map(entry => [entry.route, entry.isTab])).toEqual(moduleRoutes.map(route => [route, false]))
    expect(entries.filter(entry => entry.route.includes('?tab=')).map(entry => entry.isTab)).toEqual(Array(13).fill(true))
  })

  it('offers no entry on the former tabs of the industry screen, the former group industry path nor the balance tabs', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    const routes = routesOf(listNavigationEntries({ isAdmin: false }))

    expect(routes.filter(route => route.startsWith('/industry?') || route.startsWith('/group-industry') || route.startsWith('/balance?'))).toEqual([])
  })

  it('uses the English label as alias in English', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    const entries = listNavigationEntries({ isAdmin: false })

    expect(entries.filter(entry => entry.aliasLabel !== entry.label)).toEqual([])
  })

  it('labels entries in French and keeps the English full label as alias', () => {
    const { listNavigationEntries } = mountNavigationEntries('fr')

    expect(withoutSettingsEntries(listNavigationEntries({ isAdmin: false })).map(entry => [entry.label, entry.aliasLabel])).toEqual([
      ['Accueil', 'Home'],
      ['Industrie', 'Industry'],
      ['Industrie › Projets', 'Industry › Projects'],
      ['Industrie › Projets › Disponibles', 'Industry › Projects › Available'],
      ['Industrie › Analyse', 'Industry › Analysis'],
      ['Industrie › Analyse › Marges', 'Industry › Analysis › Margins'],
      ['Industrie › Analyse › Scan', 'Industry › Analysis › Batch Scan'],
      ['Industrie › Analyse › Achat vs Prod', 'Industry › Analysis › Buy vs Build'],
      ['Industrie › Analyse › Pivot', 'Industry › Analysis › Pivot'],
      ['Industrie › Production', 'Industry › Production'],
      ['Industrie › Production › Slots', 'Industry › Production › Slots'],
      ['Industrie › Production › Stockpile', 'Industry › Production › Stockpile'],
      ['Industrie › Production › Blueprints', 'Industry › Production › Blueprints'],
      ['Minage', 'Mining'],
      ['Minage › Disponibles', 'Mining › Available'],
      ['Minage › Archive', 'Mining › Archive'],
      ['Minage › Lunes', 'Mining › Moons'],
      ['Minage › Lunes › Taxe', 'Mining › Moons › Tax'],
      ['Minage › Sites', 'Mining › Sites'],
      ['Minage › Valeur du minerai', 'Mining › Ore value'],
      ['Minage › Mon minage', 'Mining › My mining'],
      ['Minage › Mon minage › Saison', 'Mining › My mining › Season'],
      ['PVE', 'PVE'],
      ['PVE › Escalations', 'PVE › Escalations'],
      ['Planétaire', 'Planetary'],
      ['Commerce', 'Commerce'],
      ['Commerce › Alertes', 'Commerce › Alerts'],
      ['Commerce › Évaluateur', 'Commerce › Appraisal'],
      ['Commerce › Contrats', 'Commerce › Contracts'],
      ['Patrimoine', 'Wealth'],
      ['Patrimoine › Inventaire', 'Wealth › Inventory'],
      ['Compte', 'Account'],
    ])
  })

  it('never starts a label with a sidebar group name', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    const labelsWithGroupName = listNavigationEntries({ isAdmin: false })
      .filter(entry => [entry.label, entry.aliasLabel].some(label => label.startsWith('Production') || label.startsWith('Economy')))

    expect(labelsWithGroupName).toEqual([])
  })

  it('lists every page of the registry exactly once and no route twice', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')
    const routes = routesOf(listNavigationEntries({ isAdmin: true }))
    const registryPageRoutes = allNavEntries.flatMap(module => ((module as { pages?: { route: string }[] }).pages ?? []).map(page => page.route))

    expect(registryPageRoutes).toHaveLength(18)
    expect(registryPageRoutes.map(route => routes.filter(listed => listed === route).length)).toEqual(registryPageRoutes.map(() => 1))
    expect(new Set(routes).size).toBe(routes.length)
  })

  it('lists one entry per settings section under Account › Settings, labelled in English', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    const entries = listNavigationEntries({ isAdmin: false })

    expect(entries.filter(entry => entry.route.startsWith('/settings')).map(entry => entry.route)).toEqual(['/settings', ...SETTINGS_SECTION_ROUTES])
    expect(entries.find(entry => entry.route === '/settings')?.label).toBe('Account › Settings')
    expect(entries.find(entry => entry.route === '/settings/industry')).toEqual({
      route: '/settings/industry',
      label: 'Account › Settings › Industry',
      aliasLabel: 'Account › Settings › Industry',
      isTab: true,
    })
  })

  it('adds the admin screen last for an administrator', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    const entries = listNavigationEntries({ isAdmin: true })

    expect(entries).toHaveLength(41)
    expect(entries[40]).toEqual({ route: '/admin', label: 'Administration', aliasLabel: 'Administration', isTab: false })
  })

  it('leaves the admin screen out for a user who is not an administrator', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    expect(routesOf(listNavigationEntries({ isAdmin: false }))).not.toContain('/admin')
  })

  it('lists the contracts page right after the appraisal page, as a tab of the Commerce module', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    const entries = listNavigationEntries({ isAdmin: false })

    expect(entries.filter(entry => entry.route === '/contracts')).toEqual([
      { route: '/contracts', label: 'Commerce › Contracts', aliasLabel: 'Commerce › Contracts', isTab: true },
    ])
    expect(routesOf(entries).indexOf('/contracts')).toBe(routesOf(entries).indexOf('/appraisal') + 1)
  })

  it('keeps the whole Commerce module when only the market id is hidden', () => {
    hideModules(['market'])
    const { listNavigationEntries } = mountNavigationEntries('en')

    const routes = routesOf(listNavigationEntries({ isAdmin: false }))

    expect(routes).toEqual(expect.arrayContaining(['/market', '/market?tab=alerts', '/appraisal', '/contracts']))
    expect(routes).toHaveLength(40)
  })

  it.each([
    ['the commerce id', ['commerce']],
    ['the market and appraisal ids', ['market', 'appraisal']],
  ])('hides the pages and tabs of Commerce for %s and keeps the inventory', (_case, hiddenIds) => {
    hideModules(hiddenIds)
    const { listNavigationEntries } = mountNavigationEntries('en')

    const routes = routesOf(listNavigationEntries({ isAdmin: false }))

    expect(routes.filter(route => route === '/contracts' || route === '/appraisal' || route.startsWith('/market'))).toEqual([])
    expect(routes).toContain('/assets')
    expect(routes).toHaveLength(36)
  })

  it('hides the pages of Wealth when the former ledger and assets ids are both hidden', () => {
    hideModules(['ledger', 'assets'])
    const { listNavigationEntries } = mountNavigationEntries('en')

    const routes = routesOf(listNavigationEntries({ isAdmin: false }))

    expect(routes.filter(route => route === '/balance' || route === '/assets')).toEqual([])
    expect(routes).toContain('/market')
    expect(routes).toHaveLength(38)
  })

  it('removes the screen, the tabs and the projects of each hidden module', () => {
    hideModules(['industry', 'commerce'])
    const { listNavigationEntries } = mountNavigationEntries('en')

    expect(withoutSettingsSections(routesOf(listNavigationEntries({ isAdmin: false })))).toEqual([
      '/dashboard',
      '/mining/operations',
      '/mining/operations?tab=available',
      '/mining/operations?tab=archive',
      '/mining/moons',
      '/mining/moons?tab=tax',
      '/mining/sites',
      '/mining/ore-value',
      '/mining/my-mining',
      '/mining/my-mining?tab=season',
      '/pve/revenue',
      '/pve/escalations',
      '/planetary',
      '/balance',
      '/assets',
      '/characters',
      '/settings',
    ])
  })

  it('keeps hiding the projects entries for a user who had hidden the former group industry module, and leaves industry visible', () => {
    hideModules(['group-industry'])
    const { listNavigationEntries } = mountNavigationEntries('en')

    const routes = routesOf(listNavigationEntries({ isAdmin: false }))

    expect(routes).toContain('/industry')
    expect(routes).toContain('/industry/production?tab=stockpile')
    expect(routes.filter(route => route.startsWith('/industry/projects'))).toEqual([])
    expect(routes).toHaveLength(38)
    expect(localStorage.getItem(HIDDEN_MODULES_STORAGE_KEY)).toBe('["group-industry"]')
  })

  it('keeps hiding every mining page for a user who had hidden the former mining operations module', () => {
    hideModules(['mining-operations'])
    const { listNavigationEntries } = mountNavigationEntries('en')

    const routes = routesOf(listNavigationEntries({ isAdmin: false }))

    expect(routes.filter(route => route.startsWith('/mining/'))).toEqual([])
    expect(routes).toContain('/planetary')
    expect(routes).toHaveLength(31)
    expect(localStorage.getItem(HIDDEN_MODULES_STORAGE_KEY)).toBe('["mining-operations"]')
  })

  it('keeps the account module, its settings entries and admin even when listed as hidden modules', () => {
    hideModules(['characters', 'account', 'admin'])
    const { listNavigationEntries } = mountNavigationEntries('en')

    const routes = routesOf(listNavigationEntries({ isAdmin: true }))

    expect(routes).toEqual(expect.arrayContaining(['/characters', '/settings', ...SETTINGS_SECTION_ROUTES, '/admin']))
  })
})
