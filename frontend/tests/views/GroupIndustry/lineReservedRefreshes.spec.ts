import { groupProjectRefreshes } from '@/views/GroupIndustry/groupProjectRefreshes'

describe('groupProjectRefreshes on a line reservation', () => {
  it('refreshes the BOM only on line_reserved', () => {
    expect(groupProjectRefreshes('line_reserved')).toEqual(['bom'])
  })
})
