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
      ['/industry?tab=margins', 'Industry › Margins', true],
      ['/industry?tab=batch', 'Industry › Batch Scan', true],
      ['/industry?tab=buy-vs-build', 'Industry › Buy vs Build', true],
      ['/industry?tab=pivot', 'Industry › Pivot', true],
      ['/industry?tab=slots', 'Industry › Slots', true],
      ['/industry?tab=stockpile', 'Industry › Stockpile', true],
      ['/industry?tab=blueprints', 'Industry › Blueprints', true],
      ['/group-industry', 'Industry › Projects', false],
      ['/group-industry?tab=available', 'Industry › Projects › Available', true],
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
      ['/ledger', 'Revenue', false],
      ['/ledger?tab=pve', 'Revenue › PVE', true],
      ['/ledger?tab=escalations', 'Revenue › Escalations', true],
      ['/ledger?tab=settings', 'Revenue › Settings', true],
      ['/assets', 'Inventory', false],
      ['/assets?tab=contracts', 'Inventory › Contracts', true],
      ['/characters', 'Characters', false],
    ])
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
      ['Industrie › Marges', 'Industry › Margins'],
      ['Industrie › Scan', 'Industry › Batch Scan'],
      ['Industrie › Achat vs Prod', 'Industry › Buy vs Build'],
      ['Industrie › Pivot', 'Industry › Pivot'],
      ['Industrie › Slots', 'Industry › Slots'],
      ['Industrie › Stockpile', 'Industry › Stockpile'],
      ['Industrie › Blueprints', 'Industry › Blueprints'],
      ['Industrie › Projets', 'Industry › Projects'],
      ['Industrie › Projets › Disponibles', 'Industry › Projects › Available'],
      ['Appraisal', 'Appraisal'],
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
      ['Revenus', 'Revenue'],
      ['Revenus › PVE', 'Revenue › PVE'],
      ['Revenus › Escalations', 'Revenue › Escalations'],
      ['Revenus › Parametres', 'Revenue › Settings'],
      ['Inventaire', 'Inventory'],
      ['Inventaire › Contrats', 'Inventory › Contracts'],
      ['Personnages', 'Characters'],
    ])
  })

  it('never includes the sidebar group name in a label', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    const labelsWithGroupName = listNavigationEntries({ isAdmin: false })
      .filter(entry => entry.label.includes('Production') || entry.aliasLabel.includes('Production'))

    expect(labelsWithGroupName).toEqual([])
  })

  it('adds the admin screen last for an administrator', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    const entries = listNavigationEntries({ isAdmin: true })

    expect(entries).toHaveLength(32)
    expect(entries[31]).toEqual({ route: '/admin', label: 'Administration', aliasLabel: 'Administration', isTab: false })
  })

  it('leaves the admin screen out for a user who is not an administrator', () => {
    const { listNavigationEntries } = mountNavigationEntries('en')

    expect(routesOf(listNavigationEntries({ isAdmin: false }))).not.toContain('/admin')
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
      '/assets?tab=contracts',
      '/characters',
    ])
  })

  it('keeps hiding the projects entries for a user who had hidden the former group industry module, and leaves industry visible', () => {
    hideModules(['group-industry'])
    const { listNavigationEntries } = mountNavigationEntries('en')

    const routes = routesOf(listNavigationEntries({ isAdmin: false }))

    expect(routes).toContain('/industry')
    expect(routes).toContain('/industry?tab=stockpile')
    expect(routes.filter(route => route.startsWith('/group-industry'))).toEqual([])
    expect(routes).toHaveLength(29)
    expect(localStorage.getItem(HIDDEN_MODULES_STORAGE_KEY)).toBe('["group-industry"]')
  })

  it('keeps hiding every mining page for a user who had hidden the former mining operations module', () => {
    hideModules(['mining-operations'])
    const { listNavigationEntries } = mountNavigationEntries('en')

    const routes = routesOf(listNavigationEntries({ isAdmin: false }))

    expect(routes.filter(route => route.startsWith('/mining/'))).toEqual([])
    expect(routes).toContain('/planetary')
    expect(routes).toHaveLength(22)
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
    expect(listNavigationEntries({ isAdmin: false })).toHaveLength(30)
  })
})
