import { groupProjectRefreshes } from '@/views/GroupIndustry/groupProjectRefreshes'

describe('groupProjectRefreshes for the settlement of a project', () => {
  it('refreshes the settlement alone when a transfer is ticked as received', () => {
    expect(groupProjectRefreshes('transfer_received')).toEqual(['settlement'])
  })
})
