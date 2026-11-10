import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import * as sidebarNavigation from '@/constants/sidebarNavigation'
import { adminNavItem, allNavEntries, isModuleHidden, isNavItemActive, moduleIdsToUnhide, settableModules } from '@/constants/sidebarNavigation'

interface PageEntry {
  route: string
  labelKey: string
}

function pagesOf(module: object): PageEntry[] {
  const { pages } = module as { pages?: PageEntry[] }
  return pages ?? []
}

function messageAt(messages: unknown, key: string): unknown {
  return key.split('.').reduce<unknown>((node, part) => (typeof node === 'object' && node !== null ? (node as Record<string, unknown>)[part] : undefined), messages)
}

describe('sidebar navigation registry', () => {
  it('declares the eight modules in order with their route and their pages, and no collapsible group', () => {
    expect(allNavEntries.map(module => [module.id, module.route, pagesOf(module).map(page => page.route)])).toEqual([
      ['dashboard', '/dashboard', []],
      ['industry', '/industry', ['/industry', '/industry/projects', '/industry/analysis', '/industry/production']],
      ['mining', '/mining/operations', ['/mining/operations', '/mining/moons', '/mining/sites', '/mining/ore-value', '/mining/my-mining']],
      ['pve', '/pve/revenue', ['/pve/revenue', '/pve/escalations']],
      ['planetary', '/planetary', []],
      ['commerce', '/market', ['/market', '/appraisal', '/contracts']],
      ['patrimoine', '/balance', ['/balance', '/assets']],
      ['account', '/characters', ['/characters', '/settings']],
    ])
    expect(allNavEntries.filter(module => ['dashboard', 'planetary', 'patrimoine'].includes(module.id)).map(module => module.labelKey)).toEqual([
      'nav.dashboard',
      'nav.planetary',
      'nav.patrimoine',
    ])
    expect('isNavGroup' in sidebarNavigation).toBe(false)
    expect(adminNavItem.route).toBe('/admin')
  })

  it('labels every module and page with a key translated in English and in French', () => {
    const labelKeys = allNavEntries.flatMap(module => [module.labelKey, ...pagesOf(module).map(page => page.labelKey)])

    const untranslated = labelKeys.filter(key => [en, fr].some(messages => typeof messageAt(messages, key) !== 'string' || messageAt(messages, key) === ''))

    expect(untranslated).toEqual([])
    expect(messageAt(en, 'nav.dashboard')).toBe('Home')
    expect(messageAt(en, 'nav.planetary')).toBe('Planetary')
    expect(messageAt(en, 'nav.patrimoine')).toBe('Wealth')
    expect(messageAt(fr, 'nav.dashboard')).toBe('Accueil')
    expect(messageAt(fr, 'nav.planetary')).toBe('Planétaire')
    expect(messageAt(fr, 'nav.patrimoine')).toBe('Patrimoine')
  })

  it('activates a module on its pages and on its path prefixes, including details and invitations', () => {
    expect(allNavEntries.map(module => module.id)).toHaveLength(8)
    const moduleOf = (id: string) => allNavEntries.find(module => module.id === id)!

    expect([
      isNavItemActive(moduleOf('mining'), '/mining/sites/6c1d90a4'),
      isNavItemActive(moduleOf('mining'), '/mining-operations'),
      isNavItemActive(moduleOf('mining'), '/mining-sites'),
      isNavItemActive(moduleOf('industry'), '/industry/join/AB12CD'),
      isNavItemActive(moduleOf('industry'), '/join/AB12CD'),
      isNavItemActive(moduleOf('pve'), '/pve/escalations'),
      isNavItemActive(moduleOf('account'), '/settings/mining'),
      isNavItemActive(moduleOf('commerce'), '/contracts'),
      isNavItemActive(moduleOf('patrimoine'), '/assets'),
      isNavItemActive(moduleOf('dashboard'), '/assets'),
    ]).toEqual([true, true, true, true, true, true, true, true, true, false])
  })

  it('lets the user hide the seven modules except Account, which carries the settings', () => {
    expect(settableModules.map(module => module.id)).toEqual(['dashboard', 'industry', 'mining', 'pve', 'planetary', 'commerce', 'patrimoine'])
  })

  it('reads the legacy page ids of the former economy group as the hidden state of Commerce and Wealth', () => {
    expect([
      isModuleHidden(['commerce'], 'commerce'),
      isModuleHidden(['market', 'appraisal'], 'commerce'),
      isModuleHidden(['market'], 'commerce'),
      isModuleHidden(['appraisal'], 'commerce'),
      isModuleHidden(['patrimoine'], 'patrimoine'),
      isModuleHidden(['ledger', 'assets'], 'patrimoine'),
      isModuleHidden(['ledger'], 'patrimoine'),
      isModuleHidden(['account'], 'account'),
    ]).toEqual([true, true, false, false, true, true, false, false])
    expect(moduleIdsToUnhide('commerce')).toEqual(['commerce', 'market', 'appraisal'])
    expect(moduleIdsToUnhide('patrimoine')).toEqual(['patrimoine', 'ledger', 'assets'])
    expect(moduleIdsToUnhide('mining')).toEqual(['mining', 'mining-operations'])
  })
})
