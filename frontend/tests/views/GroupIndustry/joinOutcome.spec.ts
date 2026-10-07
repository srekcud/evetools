import { joinOutcome } from '@/views/GroupIndustry/joinOutcome'

// The join view must redirect to the project detail only when the membership is accepted:
// the detail endpoint answers 403 to pending members (other corporation, removed member rejoining).
describe('joinOutcome', () => {
  it('redirects to the project detail when the membership is accepted', () => {
    expect(joinOutcome({ myStatus: 'accepted' })).toBe('redirect')
  })

  it('shows the pending approval screen when the membership is pending', () => {
    expect(joinOutcome({ myStatus: 'pending' })).toBe('pending')
  })

  it('shows the pending approval screen when there is no membership status', () => {
    expect(joinOutcome({ myStatus: null })).toBe('pending')
  })
})
