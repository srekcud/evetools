import type { LocationQuery, RouteLocationRaw } from 'vue-router'

export type IndustryTestPage = 'workshop' | 'analysis' | 'production'

export const ANALYSIS_TABS = ['margins', 'batch', 'buy-vs-build', 'pivot']
export const PRODUCTION_TABS = ['slots', 'stockpile', 'blueprints']

const PATH_OF_ROUTE_NAME: Record<string, string> = {
  industry: '/industry',
  'industry-projects': '/industry/projects',
  'industry-analysis': '/industry/analysis',
  'industry-production': '/industry/production',
}

export interface Landing {
  path: string
  query: Record<string, string>
}

export function industryPageOfTab(tab: unknown): IndustryTestPage {
  if (typeof tab === 'string' && ANALYSIS_TABS.includes(tab)) return 'analysis'
  if (typeof tab === 'string' && PRODUCTION_TABS.includes(tab)) return 'production'
  return 'workshop'
}

export function landingOf(location: RouteLocationRaw): Landing {
  if (typeof location === 'string') {
    const url = new URL(location, 'https://evetools.test')
    return { path: url.pathname, query: Object.fromEntries(url.searchParams) }
  }
  const query = Object.fromEntries(
    Object.entries(location.query ?? {}).map(([key, value]) => [key, String(value)]),
  )
  if ('path' in location && location.path !== undefined) {
    return { path: location.path, query }
  }
  if ('name' in location && typeof location.name === 'string') {
    return { path: PATH_OF_ROUTE_NAME[location.name] ?? `unknown route ${location.name}`, query }
  }
  return { path: 'unsupported location', query }
}

export function queryOfLocation(location: RouteLocationRaw): LocationQuery {
  return landingOf(location).query
}
