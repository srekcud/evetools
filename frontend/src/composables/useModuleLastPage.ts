export const MODULE_LAST_PAGE_STORAGE_KEY = 'evetools_module_last_page'

const MODULE_PAGES: Record<string, readonly string[]> = {
  industry: [
    '/industry',
    '/industry/projects',
    '/industry/analysis',
    '/industry/production',
  ],
  mining: [
    '/mining/operations',
    '/mining/moons',
    '/mining/sites',
    '/mining/ore-value',
    '/mining/my-mining',
  ],
  pve: [
    '/pve/revenue',
    '/pve/escalations',
  ],
}

function readStoredPages(): Record<string, unknown> {
  try {
    const stored = localStorage.getItem(MODULE_LAST_PAGE_STORAGE_KEY)
    const parsed: unknown = stored === null ? null : JSON.parse(stored)
    return typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed) ? parsed as Record<string, unknown> : {}
  } catch {
    return {}
  }
}

const EXACT_MATCH_PAGES: readonly string[] = ['/industry']

function listPageOf(path: string, pages: readonly string[]): string | undefined {
  return pages.find(page => path === page || (!EXACT_MATCH_PAGES.includes(page) && path.startsWith(`${page}/`)))
}

export function lastPageOf(moduleId: string, fallback: string): string {
  const pages = MODULE_PAGES[moduleId]
  const stored = readStoredPages()[moduleId]
  if (pages === undefined || typeof stored !== 'string') {
    return fallback
  }
  return pages.includes(stored) ? stored : fallback
}

export function rememberModulePage(path: string): void {
  for (const [moduleId, pages] of Object.entries(MODULE_PAGES)) {
    const listPage = listPageOf(path, pages)
    if (listPage === undefined) {
      continue
    }
    try {
      localStorage.setItem(MODULE_LAST_PAGE_STORAGE_KEY, JSON.stringify({ ...readStoredPages(), [moduleId]: listPage }))
    } catch {
      return
    }
  }
}

export function useModuleLastPage(): { lastPageOf: typeof lastPageOf } {
  return { lastPageOf }
}
