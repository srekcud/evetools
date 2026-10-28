import type { NavigationEntry } from '@/composables/useNavigationEntries'
import { normalizeSearchText, searchNavigationEntries } from '@/utils/commandPaletteSearch'

type CatalogRow = [route: string, label: string, aliasLabel: string]

const CATALOG_ROWS: CatalogRow[] = [
  ['/dashboard', 'Tableau de bord', 'Dashboard'],
  ['/ledger', 'Revenus', 'Revenue'],
  ['/ledger?tab=pve', 'Revenus › PVE', 'Revenue › PVE'],
  ['/ledger?tab=mining', 'Revenus › Minage', 'Revenue › Mining'],
  ['/ledger?tab=escalations', 'Revenus › Escalations', 'Revenue › Escalations'],
  ['/ledger?tab=settings', 'Revenus › Parametres', 'Revenue › Settings'],
  ['/industry', 'Industrie', 'Industry'],
  ['/industry?tab=projects', 'Industrie › Projets', 'Industry › Projects'],
  ['/industry?tab=margins', 'Industrie › Marges', 'Industry › Margins'],
  ['/industry?tab=batch', 'Industrie › Scan', 'Industry › Batch Scan'],
  ['/industry?tab=buy-vs-build', 'Industrie › Achat vs Prod', 'Industry › Buy vs Build'],
  ['/industry?tab=pivot', 'Industrie › Pivot', 'Industry › Pivot'],
  ['/industry?tab=slots', 'Industrie › Slots', 'Industry › Slots'],
  ['/industry?tab=stockpile', 'Industrie › Stockpile', 'Industry › Stockpile'],
  ['/group-industry', 'Industrie de groupe', 'Group Industry'],
  ['/group-industry?tab=available', 'Industrie de groupe › Disponibles', 'Group Industry › Available'],
  ['/appraisal', 'Appraisal', 'Appraisal'],
  ['/planetary', 'PI', 'PI'],
  ['/market', 'Marche', 'Market'],
  ['/market?tab=alerts', 'Marche › Alertes', 'Market › Alerts'],
  ['/assets', 'Inventaire', 'Inventory'],
  ['/assets?tab=contracts', 'Inventaire › Contrats', 'Inventory › Contracts'],
  ['/characters', 'Personnages', 'Characters'],
]

const FRENCH_CATALOG: NavigationEntry[] = CATALOG_ROWS.map(([route, label, aliasLabel]) => ({
  route,
  label,
  aliasLabel,
  isTab: route.includes('?tab='),
}))

const ENGLISH_CATALOG: NavigationEntry[] = CATALOG_ROWS.map(([route, , aliasLabel]) => ({
  route,
  label: aliasLabel,
  aliasLabel,
  isTab: route.includes('?tab='),
}))

const ADMIN_ENTRY: NavigationEntry = {
  route: '/admin',
  label: 'Administration',
  aliasLabel: 'Administration',
  isTab: false,
}

function labelsFound(catalog: readonly NavigationEntry[], query: string): string[] {
  return searchNavigationEntries(catalog, query).map(entry => entry.label)
}

describe('normalizeSearchText', () => {
  it('lowercases, strips accents, trims and collapses inner spaces', () => {
    expect(normalizeSearchText('  Marché   NOIR  ')).toBe('marche noir')
  })

  it('strips accents from capital letters', () => {
    expect(normalizeSearchText('Évaluateur Paramètres')).toBe('evaluateur parametres')
  })
})

describe('searchNavigationEntries', () => {
  it('proposes only screens, in declaration order, for an empty query', () => {
    expect(labelsFound(ENGLISH_CATALOG, '')).toEqual([
      'Dashboard',
      'Revenue',
      'Industry',
      'Group Industry',
      'Appraisal',
      'PI',
      'Market',
      'Inventory',
      'Characters',
    ])
  })

  it('treats a query made only of spaces as an empty query', () => {
    expect(labelsFound(ENGLISH_CATALOG, '   ')).toEqual(labelsFound(ENGLISH_CATALOG, ''))
    expect(labelsFound(ENGLISH_CATALOG, '   ')).toHaveLength(9)
  })

  it('returns the matching entry objects themselves', () => {
    expect(searchNavigationEntries(ENGLISH_CATALOG, 'buy')).toEqual([
      { route: '/industry?tab=buy-vs-build', label: 'Industry › Buy vs Build', aliasLabel: 'Industry › Buy vs Build', isTab: true },
    ])
  })

  it('matches a padded uppercase query after normalisation', () => {
    expect(labelsFound(ENGLISH_CATALOG, '  BUY  ')).toEqual(['Industry › Buy vs Build'])
  })

  it('requires every query word to be a substring of the full label, in any order', () => {
    expect(labelsFound(ENGLISH_CATALOG, 'ind buy')).toEqual(['Industry › Buy vs Build'])
    expect(labelsFound(ENGLISH_CATALOG, 'buy ind')).toEqual(['Industry › Buy vs Build'])
    expect(labelsFound(ENGLISH_CATALOG, 'ind zzz')).toEqual([])
  })

  it('ranks labels starting with the query, then labels with a word starting with it, then the rest', () => {
    expect(labelsFound(ENGLISH_CATALOG, 'a')).toEqual([
      'Appraisal',
      'Group Industry › Available',
      'Market › Alerts',
      'Dashboard',
      'Revenue › Escalations',
      'Industry › Margins',
      'Industry › Batch Scan',
      'Market',
      'Inventory › Contracts',
      'Characters',
    ])
  })

  it('keeps declaration order between entries of the same rank', () => {
    expect(labelsFound(ENGLISH_CATALOG, 'ind')).toEqual([
      'Industry',
      'Industry › Projects',
      'Industry › Margins',
      'Industry › Batch Scan',
      'Industry › Buy vs Build',
      'Industry › Pivot',
      'Industry › Slots',
      'Industry › Stockpile',
      'Group Industry',
      'Group Industry › Available',
    ])
  })

  it('returns at most ten entries, dropping the lowest ranked ones', () => {
    expect(labelsFound([...ENGLISH_CATALOG, ADMIN_ENTRY], 'a')).toEqual([
      'Appraisal',
      'Administration',
      'Group Industry › Available',
      'Market › Alerts',
      'Dashboard',
      'Revenue › Escalations',
      'Industry › Margins',
      'Industry › Batch Scan',
      'Market',
      'Inventory › Contracts',
    ])
  })

  it('cuts at ten entries when more than ten have the best rank', () => {
    expect(labelsFound(ENGLISH_CATALOG, 'i')).toEqual([
      'Industry',
      'Industry › Projects',
      'Industry › Margins',
      'Industry › Batch Scan',
      'Industry › Buy vs Build',
      'Industry › Pivot',
      'Industry › Slots',
      'Industry › Stockpile',
      'Inventory',
      'Inventory › Contracts',
    ])
  })

  it('matches a French entry on its English alias while keeping the French label', () => {
    expect(labelsFound(FRENCH_CATALOG, 'buy')).toEqual(['Industrie › Achat vs Prod'])
    expect(labelsFound(FRENCH_CATALOG, 'contracts')).toEqual(['Inventaire › Contrats'])
  })

  it('matches an accented query against an unaccented French label', () => {
    expect(labelsFound(FRENCH_CATALOG, 'marché')).toEqual(['Marche', 'Marche › Alertes'])
  })

  it('ranks a French entry on its French label first', () => {
    expect(labelsFound(FRENCH_CATALOG, 'ma')).toEqual(['Marche', 'Marche › Alertes', 'Industrie › Marges'])
  })

  it('ranks a French entry on its English alias when the French label does not match', () => {
    expect(labelsFound(FRENCH_CATALOG, 'cha')).toEqual(['Personnages', 'Industrie › Achat vs Prod'])
  })
})
