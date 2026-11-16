import { readAssetFilterQuery, writeAssetFilterQuery, type AssetFilterState } from '@/utils/assetFilterQuery'

const defaultState: AssetFilterState = {
  viewMode: 'character',
  characterId: null,
  search: '',
  solarSystem: '',
  location: '',
  division: '',
  locationSort: 'items',
  itemSort: 'name',
}

describe('assetFilterQuery item sort', () => {
  it('reads the item sort from the itemSort key', () => {
    expect(readAssetFilterQuery({ itemSort: 'quantity' }).itemSort).toBe('quantity')
    expect(readAssetFilterQuery({ itemSort: 'volume' }).itemSort).toBe('volume')
  })

  it('defaults to the name sort and ignores an unknown value', () => {
    expect(readAssetFilterQuery({}).itemSort).toBe('name')
    expect(readAssetFilterQuery({ itemSort: 'price' }).itemSort).toBe('name')
  })

  it('keeps the location sort on the sort key', () => {
    const state = readAssetFilterQuery({ sort: 'volume', itemSort: 'quantity' })

    expect(state.locationSort).toBe('volume')
    expect(state.itemSort).toBe('quantity')
  })

  it('writes the item sort only when it is not the default', () => {
    expect(writeAssetFilterQuery({}, { ...defaultState, itemSort: 'quantity' })).toMatchObject({ itemSort: 'quantity' })
    expect(writeAssetFilterQuery({ itemSort: 'quantity' }, defaultState)).not.toHaveProperty('itemSort')
  })

  it('writes both sorts side by side', () => {
    expect(writeAssetFilterQuery({}, { ...defaultState, locationSort: 'name', itemSort: 'volume' })).toMatchObject({ sort: 'name', itemSort: 'volume' })
  })
})
