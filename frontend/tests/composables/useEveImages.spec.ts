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
})
