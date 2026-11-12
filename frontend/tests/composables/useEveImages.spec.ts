import { useEveImages } from '@/composables/useEveImages'

describe('useEveImages', () => {
  const { getBlueprintIconUrl, getTypeIconUrl } = useEveImages()

  it('serves a blueprint original from the bp image variation', () => {
    expect(getBlueprintIconUrl(21014, 'bpo')).toBe('https://images.evetech.net/types/21014/bp?size=32')
  })

  it('serves a blueprint copy from the bpc image variation', () => {
    expect(getBlueprintIconUrl(971, 'bpc', 64)).toBe('https://images.evetech.net/types/971/bpc?size=64')
  })

  it('keeps the icon variation for ordinary types', () => {
    expect(getTypeIconUrl(34)).toBe('https://images.evetech.net/types/34/icon?size=32')
  })

  it('serves a type of the blueprint category from the bp variation, since it has no icon', () => {
    expect(getTypeIconUrl(691, 32, 9)).toBe('https://images.evetech.net/types/691/bp?size=32')
  })

  it('serves a skin from the fallback icon without a network request', () => {
    expect(getTypeIconUrl(42000, 32, 91)).toMatch(/^data:image\/svg\+xml,/)
  })
})
