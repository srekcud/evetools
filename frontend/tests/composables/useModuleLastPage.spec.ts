import { MODULE_LAST_PAGE_STORAGE_KEY, lastPageOf, rememberModulePage } from '@/composables/useModuleLastPage'

const OPERATION_ID = '0b8f5d2e-4c1a-4e7b-9a36-5d2f8c1e7a40'
const FALLBACK = '/mining/operations'
const INDUSTRY_FALLBACK = '/industry'
const PVE_FALLBACK = '/pve/revenue'

function storedPages(): unknown {
  return JSON.parse(localStorage.getItem(MODULE_LAST_PAGE_STORAGE_KEY) ?? 'null')
}

beforeEach(() => {
  localStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('useModuleLastPage', () => {
  it.each([
    ['a list page', '/mining/moons', '/mining/moons'],
    ['an operation detail', `/mining/operations/${OPERATION_ID}`, '/mining/operations'],
    ['an operation invitation', '/mining/operations/join/AB12CD', '/mining/operations'],
    ['a site detail', '/mining/sites/6c1d90a4', '/mining/sites'],
  ])('remembers the list page of %s', (_case, visited, expectedPage) => {
    rememberModulePage(visited)

    expect(storedPages()).toEqual({ mining: expectedPage })
    expect(lastPageOf('mining', FALLBACK)).toBe(expectedPage)
  })

  it.each([
    ['the revenue page', '/pve/revenue'],
    ['the escalations page', '/pve/escalations'],
  ])('remembers %s of the pve module without touching the mining page', (_case, visited) => {
    rememberModulePage('/mining/moons')

    rememberModulePage(visited)

    expect(storedPages()).toEqual({ mining: '/mining/moons', pve: visited })
    expect(lastPageOf('pve', PVE_FALLBACK)).toBe(visited)
    expect(lastPageOf('mining', FALLBACK)).toBe('/mining/moons')
  })

  it.each([
    ['an unknown path', { pve: '/pve/elsewhere' }],
    ['a path of the mining module', { pve: '/mining/moons' }],
    ['no entry for the module', { mining: '/mining/moons' }],
  ])('falls back to the revenue page for %s', (_case, stored) => {
    localStorage.setItem(MODULE_LAST_PAGE_STORAGE_KEY, JSON.stringify(stored))

    expect(lastPageOf('pve', PVE_FALLBACK)).toBe(PVE_FALLBACK)
  })

  it('ignores a path outside the module and a path that only shares its prefix', () => {
    rememberModulePage('/dashboard')
    rememberModulePage('/mining-operations')
    rememberModulePage('/mining/unknown')

    expect(localStorage.getItem(MODULE_LAST_PAGE_STORAGE_KEY)).toBeNull()
  })

  it('keeps the pages stored for other modules when it records a page', () => {
    localStorage.setItem(MODULE_LAST_PAGE_STORAGE_KEY, JSON.stringify({ industry: '/industry/projects' }))

    rememberModulePage('/mining/sites')

    expect(storedPages()).toEqual({ industry: '/industry/projects', mining: '/mining/sites' })
  })

  it.each([
    ['an unknown path', { mining: '/mining/elsewhere' }],
    ['a path that is not a string', { mining: 42 }],
    ['a stored list', ['/mining/sites']],
    ['no entry for the module', { industry: '/industry' }],
  ])('falls back to the operations page for %s', (_case, stored) => {
    localStorage.setItem(MODULE_LAST_PAGE_STORAGE_KEY, JSON.stringify(stored))

    expect(lastPageOf('mining', FALLBACK)).toBe(FALLBACK)
  })

  it('falls back for a module it does not know', () => {
    expect(lastPageOf('unknown', '/dashboard')).toBe('/dashboard')
  })

  it('does not throw when the storage refuses the write or the read', () => {
    vi.stubGlobal('localStorage', {
      getItem: () => { throw new Error('blocked') },
      setItem: () => { throw new Error('quota') },
    })

    expect(() => rememberModulePage('/mining/moons')).not.toThrow()
    expect(lastPageOf('mining', FALLBACK)).toBe(FALLBACK)
  })

  it.each([
    ['the workshop', '/industry', '/industry'],
    ['the projects page', '/industry/projects', '/industry/projects'],
    ['a project detail', '/industry/projects/6c1d90a4', '/industry/projects'],
    ['the analysis page', '/industry/analysis', '/industry/analysis'],
    ['the production page', '/industry/production', '/industry/production'],
  ])('remembers the industry page of %s', (_case, visited, expectedPage) => {
    rememberModulePage(visited)

    expect(storedPages()).toEqual({ industry: expectedPage })
    expect(lastPageOf('industry', INDUSTRY_FALLBACK)).toBe(expectedPage)
  })

  it.each([
    ['the skills page', '/industry/skills'],
    ['the corporation hangars page', '/industry/hangars'],
    ['an invitation', '/industry/join/AB12CD'],
    ['the former group industry path', '/group-industry'],
  ])('remembers no industry page for %s', (_case, visited) => {
    rememberModulePage(visited)

    expect(localStorage.getItem(MODULE_LAST_PAGE_STORAGE_KEY)).toBeNull()
    expect(lastPageOf('industry', INDUSTRY_FALLBACK)).toBe(INDUSTRY_FALLBACK)
  })

  it('keeps the industry and mining pages side by side', () => {
    rememberModulePage('/industry/analysis')
    rememberModulePage('/mining/sites')

    expect(storedPages()).toEqual({ industry: '/industry/analysis', mining: '/mining/sites' })
  })

  it.each([
    ['an unknown path', { industry: '/industry/elsewhere' }],
    ['a path that is not a string', { industry: 42 }],
  ])('falls back to the workshop for %s', (_case, stored) => {
    localStorage.setItem(MODULE_LAST_PAGE_STORAGE_KEY, JSON.stringify(stored))

    expect(lastPageOf('industry', INDUSTRY_FALLBACK)).toBe(INDUSTRY_FALLBACK)
  })

  it.each([
    ['commerce', '/contracts', '/market'],
    ['patrimoine', '/assets', '/balance'],
    ['account', '/characters', '/characters'],
  ])('remembers the last page of the %s module and stores it next to the others', (moduleId, visited, fallback) => {
    rememberModulePage('/mining/moons')

    rememberModulePage(visited)

    expect(storedPages()).toEqual({ mining: '/mining/moons', [moduleId]: visited })
    expect(lastPageOf(moduleId, fallback)).toBe(visited)
  })

  it('remembers the settings page for any settings section and falls back to the module route for an unknown page', () => {
    rememberModulePage('/settings/mining')

    expect(storedPages()).toEqual({ account: '/settings' })
    expect(lastPageOf('account', '/characters')).toBe('/settings')

    localStorage.setItem(MODULE_LAST_PAGE_STORAGE_KEY, JSON.stringify({ account: '/settings', commerce: '/elsewhere', patrimoine: 7 }))

    expect(lastPageOf('commerce', '/market')).toBe('/market')
    expect(lastPageOf('patrimoine', '/balance')).toBe('/balance')
  })
})
