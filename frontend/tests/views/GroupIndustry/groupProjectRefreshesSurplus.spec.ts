import { groupProjectRefreshes, refreshesVisibleOn } from '@/views/GroupIndustry/groupProjectRefreshes'

describe('groupProjectRefreshes for the surplus stored in the corporation stock', () => {
  it('refreshes the surplus and the distribution on surplus_stored and keeps the surplus refresh whatever section is open', () => {
    expect([...groupProjectRefreshes('surplus_stored')].sort()).toEqual(['distribution', 'surplus'])
    expect(refreshesVisibleOn(['surplus_stored'], null)).toContain('surplus')
    expect(refreshesVisibleOn(['surplus_stored'], 'bom')).toContain('surplus')
  })
})
