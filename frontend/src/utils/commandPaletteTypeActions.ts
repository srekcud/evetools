export type TypeActionId = 'analyzeMargin' | 'buyVsBuild' | 'openInMarket'

export type TypeActionKey = 'Enter' | 'Shift+Enter' | 'Ctrl+Enter'

export interface TypeAction {
  id: TypeActionId
  key: TypeActionKey
  route: (typeId: number) => string
}

const INDUSTRY_MODULE = 'industry'
const MARKET_MODULE = 'market'

const ANALYZE_MARGIN: TypeAction = {
  id: 'analyzeMargin',
  key: 'Enter',
  route: typeId => `/industry?tab=margins&typeId=${typeId}`,
}

const BUY_VS_BUILD: TypeAction = {
  id: 'buyVsBuild',
  key: 'Shift+Enter',
  route: typeId => `/industry?tab=buy-vs-build&typeId=${typeId}`,
}

const OPEN_IN_MARKET: TypeAction = {
  id: 'openInMarket',
  key: 'Ctrl+Enter',
  route: typeId => `/market?tab=detail&typeId=${typeId}`,
}

export function listTypeActions(hiddenModules: readonly string[]): TypeAction[] {
  const isIndustryVisible = !hiddenModules.includes(INDUSTRY_MODULE)
  const isMarketVisible = !hiddenModules.includes(MARKET_MODULE)
  if (isIndustryVisible) {
    return isMarketVisible ? [ANALYZE_MARGIN, BUY_VS_BUILD, OPEN_IN_MARKET] : [ANALYZE_MARGIN, BUY_VS_BUILD]
  }
  return isMarketVisible ? [{ ...OPEN_IN_MARKET, key: 'Enter' }] : []
}

function keyOfEvent(event: KeyboardEvent): TypeActionKey | null {
  if (event.altKey) {
    return null
  }
  if (event.ctrlKey || event.metaKey) {
    return event.shiftKey ? null : 'Ctrl+Enter'
  }
  return event.shiftKey ? 'Shift+Enter' : 'Enter'
}

export function typeActionForEvent(actions: readonly TypeAction[], event: KeyboardEvent): TypeAction | undefined {
  const key = keyOfEvent(event)
  return actions.find(action => action.key === key)
}

export function typeActionForClick(actions: readonly TypeAction[]): TypeAction | undefined {
  return actions.find(action => action.key === 'Enter')
}
