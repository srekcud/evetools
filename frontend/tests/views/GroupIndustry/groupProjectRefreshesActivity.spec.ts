import { groupProjectRefreshes, refreshesActivityOn } from '@/views/GroupIndustry/groupProjectRefreshes'

const RECORDED_ACTIONS = [
  'contribution_submitted',
  'contribution_approved',
  'contribution_rejected',
  'member_joined',
  'member_accepted',
  'member_left',
  'sale_recorded',
  'sale_updated',
  'sale_deleted',
  'status_changed',
  'fees_updated',
  'jobs_detected',
  'jobs_finished',
]
const NOT_RECORDED_ACTIONS = ['contribution_synced', 'line_reserved', 'transfer_received', 'container_verified']

function sortedRefreshesFor(action: string): string[] {
  return [...groupProjectRefreshes(action)].sort()
}

describe('groupProjectRefreshes for the activity feed', () => {
  it('refreshes the activity feed on every recorded action', () => {
    const withoutActivity = RECORDED_ACTIONS.filter(action => !refreshesActivityOn(action))

    expect(withoutActivity).toEqual([])
  })

  it('leaves the activity feed alone on the actions that are not recorded', () => {
    const withActivity = NOT_RECORDED_ACTIONS.filter(action => refreshesActivityOn(action))

    expect(withActivity).toEqual([])
  })

  it('refreshes the project detail, the distribution and the cockpit on fees_updated', () => {
    expect(sortedRefreshesFor('fees_updated')).toEqual(expect.arrayContaining(['cockpit', 'distribution', 'projectDetail']))
  })

  it('refreshes the members and the project detail on member_accepted', () => {
    expect(sortedRefreshesFor('member_accepted')).toEqual(expect.arrayContaining(['members', 'projectDetail']))
  })
})
