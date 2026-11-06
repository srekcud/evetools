import { groupProjectRefreshes } from '@/views/GroupIndustry/groupProjectRefreshes'

function sortedRefreshesFor(action: string): string[] {
  return [...groupProjectRefreshes(action)].sort()
}

describe('groupProjectRefreshes', () => {
  it.each(['contribution_submitted', 'contribution_approved', 'contribution_rejected'])(
    'refreshes the BOM, the cockpit, the contributions and the project detail on %s',
    (action) => {
      expect(sortedRefreshesFor(action)).toEqual(['bom', 'cockpit', 'contributions', 'projectDetail'])
    },
  )

  it.each(['member_joined', 'member_left'])('refreshes the members and the project detail on %s', (action) => {
    expect(sortedRefreshesFor(action)).toEqual(['members', 'projectDetail'])
  })

  it.each(['sale_recorded', 'sale_updated', 'sale_deleted'])('refreshes the cockpit, the sales, the distribution and the sale suggestions on %s', (action) => {
    expect(sortedRefreshesFor(action)).toEqual(['cockpit', 'distribution', 'saleSuggestions', 'sales'])
  })

  it('refreshes the project detail, the BOM and the cockpit on status_changed', () => {
    expect(sortedRefreshesFor('status_changed')).toEqual(['bom', 'cockpit', 'projectDetail'])
  })
})

describe('groupProjectRefreshes on a derived contribution', () => {
  it('refreshes the BOM, the cockpit, the contributions, the distribution and the project detail on contribution_synced', () => {
    expect(sortedRefreshesFor('contribution_synced')).toEqual(['bom', 'cockpit', 'contributions', 'distribution', 'projectDetail'])
  })
})
