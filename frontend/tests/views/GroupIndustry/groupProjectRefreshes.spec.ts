import { groupProjectRefreshes } from '@/views/GroupIndustry/groupProjectRefreshes'

function sortedRefreshesFor(action: string): string[] {
  return [...groupProjectRefreshes(action)].sort()
}

describe('groupProjectRefreshes', () => {
  it.each(['contribution_submitted', 'contribution_approved', 'contribution_rejected'])(
    'refreshes the BOM, the contributions and the project detail on %s',
    (action) => {
      expect(sortedRefreshesFor(action)).toEqual(['bom', 'contributions', 'projectDetail'])
    },
  )

  it.each(['member_joined', 'member_left'])('refreshes the members and the project detail on %s', (action) => {
    expect(sortedRefreshesFor(action)).toEqual(['members', 'projectDetail'])
  })

  it.each(['sale_recorded', 'sale_updated', 'sale_deleted'])('refreshes the sales and the distribution on %s', (action) => {
    expect(sortedRefreshesFor(action)).toEqual(['distribution', 'sales'])
  })

  it('refreshes the project detail and the BOM on status_changed', () => {
    expect(sortedRefreshesFor('status_changed')).toEqual(['bom', 'projectDetail'])
  })
})
