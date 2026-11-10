export const MODULE_LAST_PAGE_STORAGE_KEY = 'evetools_module_last_page'

export function useModuleLastPage(): { lastPageOf: (moduleId: string, fallback: string) => string } {
  return { lastPageOf: (_moduleId, fallback) => fallback }
}
