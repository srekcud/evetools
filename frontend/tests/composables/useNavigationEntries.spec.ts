import { createApp, type App } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { useNavigationEntries, type NavigationEntry } from '@/composables/useNavigationEntries'

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

beforeEach(() => {
  localStorage.clear()
})

afterEach(() => {
  mountedApps.splice(0).forEach(app => app.unmount())
  localStorage.clear()
})

describe('useNavigationEntries', () => {
  it('lists every screen followed by its non default tabs, in sidebar order, with English labels', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    expect(listNavigationEntries({ isAdmin: false }).map(entry => [entry.route, entry.label, entry.isTab])).toEqual([
      ['/dashboard', 'Dashboard', false],
      ['/industry', 'Industry', false],
      ['/industry/projects', 'Industry › Projects', false],
      ['/industry/projects?tab=available', 'Industry › Projects › Available', true],
      ['/industry/analysis', 'Industry › Analysis', true],
      ['/industry/analysis?tab=margins', 'Industry › Analysis › Margins', true],
      ['/industry/analysis?tab=batch', 'Industry › Analysis › Batch Scan', true],
      ['/industry/analysis?tab=buy-vs-build', 'Industry › Analysis › Buy vs Build', true],
      ['/industry/analysis?tab=pivot', 'Industry › Analysis › Pivot', true],
      ['/industry/production', 'Industry › Production', true],
      ['/industry/production?tab=slots', 'Industry › Production › Slots', true],
      ['/industry/production?tab=stockpile', 'Industry › Production › Stockpile', true],
      ['/industry/production?tab=blueprints', 'Industry › Production › Blueprints', true],
      ['/appraisal', 'Appraisal', false],
      ['/planetary', 'PI', false],
      ['/mining/operations', 'Mining', false],
      ['/mining/operations?tab=available', 'Mining › Available', true],
      ['/mining/operations?tab=archive', 'Mining › Archive', true],
      ['/mining/moons', 'Mining › Moons', true],
      ['/mining/moons?tab=tax', 'Mining › Moons › Tax', true],
      ['/mining/sites', 'Mining › Sites', true],
      ['/mining/ore-value', 'Mining › Ore value', true],
      ['/mining/my-mining', 'Mining › My mining', true],
      ['/mining/my-mining?tab=season', 'Mining › My mining › Season', true],
      ['/market', 'Market', false],
      ['/market?tab=alerts', 'Market › Alerts', true],
      ['/contracts', 'Commerce › Contracts', true],
      ['/ledger', 'Revenue', false],
      ['/ledger?tab=pve', 'Revenue › PVE', true],
      ['/ledger?tab=escalations', 'Revenue › Escalations', true],
      ['/ledger?tab=settings', 'Revenue › Settings', true],
      ['/assets', 'Inventory', false],
      ['/characters', 'Characters', false],
    ])
  })

  it('offers no entry on the former tabs of the industry screen nor on the former group industry path', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    const routes = routesOf(listNavigationEntries({ isAdmin: false }))

    expect(routes.filter(route => route.startsWith('/industry?') || route.startsWith('/group-industry'))).toEqual([])
  })

  it('uses the English label as alias in English', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    const entries = listNavigationEntries({ isAdmin: false })

    expect(entries.filter(entry => entry.aliasLabel !== entry.label)).toEqual([])
  })

  it('labels entries in French and keeps the English full label as alias', () => {
    const { listNavigationEntries } = mountNavigationEntries('fr')

    expect(listNavigationEntries({ isAdmin: false }).map(entry => [entry.label, entry.aliasLabel])).toEqual([
      ['Tableau de bord', 'Dashboard'],
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
      ['Évaluateur', 'Appraisal'],
      ['PI', 'PI'],
      ['Minage', 'Mining'],
      ['Minage › Disponibles', 'Mining › Available'],
      ['Minage › Archive', 'Mining › Archive'],
      ['Minage › Lunes', 'Mining › Moons'],
      ['Minage › Lunes › Taxe', 'Mining › Moons › Tax'],
      ['Minage › Sites', 'Mining › Sites'],
      ['Minage › Valeur du minerai', 'Mining › Ore value'],
      ['Minage › Mon minage', 'Mining › My mining'],
      ['Minage › Mon minage › Saison', 'Mining › My mining › Season'],
      ['Marche', 'Market'],
      ['Marche › Alertes', 'Market › Alerts'],
      ['Commerce › Contrats', 'Commerce › Contracts'],
      ['Revenus', 'Revenue'],
      ['Revenus › PVE', 'Revenue › PVE'],
      ['Revenus › Escalations', 'Revenue › Escalations'],
      ['Revenus › Parametres', 'Revenue › Settings'],
      ['Inventaire', 'Inventory'],
      ['Personnages', 'Characters'],
    ])
  })

  it('never starts a label with a sidebar group name', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    const labelsWithGroupName = listNavigationEntries({ isAdmin: false })
      .filter(entry => [entry.label, entry.aliasLabel].some(label => label.startsWith('Production') || label.startsWith('Economy')))

    expect(labelsWithGroupName).toEqual([])
  })

  it('adds the admin screen last for an administrator', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    const entries = listNavigationEntries({ isAdmin: true })

    expect(entries).toHaveLength(34)
    expect(entries[33]).toEqual({ route: '/admin', label: 'Administration', aliasLabel: 'Administration', isTab: false })
  })

  it('leaves the admin screen out for a user who is not an administrator', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    expect(routesOf(listNavigationEntries({ isAdmin: false }))).not.toContain('/admin')
  })

  it('lists the contracts page right after the market tabs, as a tab of the Commerce module', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    const entries = listNavigationEntries({ isAdmin: false })

    expect(entries.filter(entry => entry.route === '/contracts')).toEqual([
      { route: '/contracts', label: 'Commerce › Contracts', aliasLabel: 'Commerce › Contracts', isTab: true },
    ])
    expect(routesOf(entries).indexOf('/contracts')).toBe(routesOf(entries).indexOf('/market?tab=alerts') + 1)
  })

  it('hides the contracts page with the market module and keeps the inventory', () => {
    hideModules(['market'])
    const { listNavigationEntries } = mountNavigationEntries('en')

    const routes = routesOf(listNavigationEntries({ isAdmin: false }))

    expect(routes.filter(route => route === '/contracts' || route.startsWith('/market'))).toEqual([])
    expect(routes).toContain('/assets')
    expect(routes).toHaveLength(28)
  })

  it('removes the screen, the tabs and the projects of each hidden module', () => {
    hideModules(['industry', 'market'])
    const { listNavigationEntries } = mountNavigationEntries('en')

    expect(routesOf(listNavigationEntries({ isAdmin: false }))).toEqual([
      '/dashboard',
      '/appraisal',
      '/planetary',
      '/mining/operations',
      '/mining/operations?tab=available',
      '/mining/operations?tab=archive',
      '/mining/moons',
      '/mining/moons?tab=tax',
      '/mining/sites',
      '/mining/ore-value',
      '/mining/my-mining',
      '/mining/my-mining?tab=season',
      '/ledger',
      '/ledger?tab=pve',
      '/ledger?tab=escalations',
      '/ledger?tab=settings',
      '/assets',
      '/characters',
    ])
  })

  it('keeps hiding the projects entries for a user who had hidden the former group industry module, and leaves industry visible', () => {
    hideModules(['group-industry'])
    const { listNavigationEntries } = mountNavigationEntries('en')

    const routes = routesOf(listNavigationEntries({ isAdmin: false }))

    expect(routes).toContain('/industry')
    expect(routes).toContain('/industry/production?tab=stockpile')
    expect(routes.filter(route => route.startsWith('/industry/projects'))).toEqual([])
    expect(routes).toHaveLength(31)
    expect(localStorage.getItem(HIDDEN_MODULES_STORAGE_KEY)).toBe('["group-industry"]')
  })

  it('keeps hiding every mining page for a user who had hidden the former mining operations module', () => {
    hideModules(['mining-operations'])
    const { listNavigationEntries } = mountNavigationEntries('en')

    const routes = routesOf(listNavigationEntries({ isAdmin: false }))

    expect(routes.filter(route => route.startsWith('/mining/'))).toEqual([])
    expect(routes).toContain('/planetary')
    expect(routes).toHaveLength(24)
    expect(localStorage.getItem(HIDDEN_MODULES_STORAGE_KEY)).toBe('["mining-operations"]')
  })

  it('keeps characters and admin even when listed as hidden modules', () => {
    hideModules(['characters', 'admin'])
    const { listNavigationEntries } = mountNavigationEntries('en')

    const routes = routesOf(listNavigationEntries({ isAdmin: true }))

    expect(routes).toContain('/characters')
    expect(routes).toContain('/admin')
  })

  it('reads the hidden modules again on each call', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')
    expect(routesOf(listNavigationEntries({ isAdmin: false }))).toContain('/planetary')

    hideModules(['planetary'])

    expect(routesOf(listNavigationEntries({ isAdmin: false }))).not.toContain('/planetary')
    expect(listNavigationEntries({ isAdmin: false })).toHaveLength(32)
  })
})
