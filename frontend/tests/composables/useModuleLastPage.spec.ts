import { MODULE_LAST_PAGE_STORAGE_KEY, lastPageOf, rememberModulePage } from '@/composables/useModuleLastPage'

const OPERATION_ID = '0b8f5d2e-4c1a-4e7b-9a36-5d2f8c1e7a40'
const FALLBACK = '/mining/operations'
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
    rememberModulePage('/industry')
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
})
