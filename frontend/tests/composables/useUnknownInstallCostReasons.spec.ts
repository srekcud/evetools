import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { useUnknownInstallCostReasons } from '@/composables/useUnknownInstallCostReasons'
import type { MissingData } from '@/stores/industry/types'

const CERAMIC_POWDER_TYPE_ID = 16_660

function reasonsOf(missingData: MissingData[]): string[] {
  return useUnknownInstallCostReasons(() => missingData).value
}

describe('useUnknownInstallCostReasons with a facility tax never entered (issue 780)', () => {
  it('maps the facility tax missing data to the missing facility tax reason', () => {
    expect(reasonsOf([{ reason: 'facility_tax', typeId: null }])).toEqual(['missing_facility_tax'])
  })

  it('lists the cost index reason before the facility tax reason and each reason once', () => {
    expect(reasonsOf([
      { reason: 'cost_index', typeId: null },
      { reason: 'facility_tax', typeId: null },
      { reason: 'facility_tax', typeId: null },
    ])).toEqual(['missing_cost_index', 'missing_facility_tax'])
  })

  it('keeps mapping the adjusted price and ignores the data that does not concern the install cost', () => {
    expect(reasonsOf([
      { reason: 'market_price', typeId: CERAMIC_POWDER_TYPE_ID },
      { reason: 'adjusted_price', typeId: CERAMIC_POWDER_TYPE_ID },
    ])).toEqual(['missing_adjusted_price'])
  })

  it('names the missing facility tax reason in English and in French', () => {
    const englishLabels: Record<string, string> = en.industry.inventionUnknown
    const frenchLabels: Record<string, string> = fr.industry.inventionUnknown

    expect(englishLabels.missing_facility_tax).toEqual(expect.any(String))
    expect(frenchLabels.missing_facility_tax).toEqual(expect.any(String))
    expect(englishLabels.missing_facility_tax).not.toBe(frenchLabels.missing_facility_tax)
  })
})
