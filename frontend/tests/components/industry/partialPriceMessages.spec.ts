import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

describe('partial price messages', () => {
  it('defines the same partial price keys in English and French', () => {
    expect(Object.keys(fr.industry.partialPrice).sort()).toEqual(Object.keys(en.industry.partialPrice).sort())
    expect(Object.keys(en.industry.partialPrice).sort()).toEqual(['marker', 'noticeLine', 'noticeTitle', 'tooltip'])
  })

  it.each([
    ['en', en],
    ['fr', fr],
  ] as const)('carries the coverage in the marker and the type with its coverage in each notice line (%s)', (_locale, messages) => {
    expect(messages.industry.partialPrice.marker).toContain('{percent}')
    expect(messages.industry.partialPrice.noticeLine).toContain('{type}')
    expect(messages.industry.partialPrice.noticeLine).toContain('{percent}')
  })
})
