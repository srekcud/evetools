import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

describe('scanner unknown sales fees tooltip', () => {
  it('has a distinct English and French wording for the missing sales fees reason', () => {
    const english = en.industry.inventionUnknown.missing_sales_fees
    const french = fr.industry.inventionUnknown.missing_sales_fees

    expect(english).toEqual(expect.any(String))
    expect(french).toEqual(expect.any(String))
    expect(french).not.toBe(english)
  })
})
