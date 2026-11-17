import type { LedgerIndustry } from '@/stores/ledger'
import type { RevenueDailyAmount, RevenueGridColumn, RevenueSeries } from '@/types/revenueGrid'

export const INDUSTRY_PROFIT_COLUMN_ID = 'profit'
export const INDUSTRY_UNKNOWN_PROFIT_COLUMN_ID = 'unknownProfit'
export const MULTI_PRODUCT_CONTRIBUTOR_ID = 'multi-product'
const TODO_BADGE_UNKNOWN_PROFIT = 1

export interface IndustryGridLabels {
  profit: string
  unknownProfit: string
  multiProduct: string
}

export function hasNoKnownProfit(line: { projectCount: number; unknownProjectCount: number }): boolean {
  return line.projectCount > 0 && line.unknownProjectCount === line.projectCount
}

function toDailyAmounts(daily: Record<string, number>): RevenueDailyAmount[] {
  return Object.entries(daily).map(([date, amount]) => ({ date, amount }))
}

function knownProductContributors(industry: LedgerIndustry, multiProductLabel: string): RevenueGridColumn['contributors'] {
  return industry.products
    .filter(product => !hasNoKnownProfit(product))
    .map(product => ({
      id: product.typeId ?? MULTI_PRODUCT_CONTRIBUTOR_ID,
      name: product.typeName ?? multiProductLabel,
      value: product.profit,
    }))
}

export function buildIndustryRevenueColumns(industry: LedgerIndustry, labels: IndustryGridLabels): RevenueGridColumn[] {
  const profitIsKnown = !hasNoKnownProfit(industry.totals)
  const profitColumn: RevenueGridColumn = {
    id: INDUSTRY_PROFIT_COLUMN_ID,
    label: labels.profit,
    tone: 'industry',
    kind: 'actual',
    value: profitIsKnown ? industry.totals.profit : null,
    change: profitIsKnown ? industry.totals.change : null,
    daily: profitIsKnown ? toDailyAmounts(industry.daily) : null,
    contributors: profitIsKnown ? knownProductContributors(industry, labels.multiProduct) : null,
  }

  if (industry.totals.unknownProjectCount === 0) {
    return [profitColumn]
  }

  const unknownProfitColumn: RevenueGridColumn = {
    id: INDUSTRY_UNKNOWN_PROFIT_COLUMN_ID,
    label: labels.unknownProfit,
    tone: 'neutral',
    kind: 'unknown',
    value: null,
    change: null,
    daily: null,
    contributors: null,
    headerBadge: TODO_BADGE_UNKNOWN_PROFIT,
  }

  return [profitColumn, unknownProfitColumn]
}

export function buildIndustryNetSeries(industry: LedgerIndustry): RevenueSeries {
  const profitIsKnown = !hasNoKnownProfit(industry.totals)

  return {
    change: profitIsKnown ? industry.totals.change : null,
    daily: profitIsKnown ? toDailyAmounts(industry.daily) : null,
    contributors: null,
  }
}
