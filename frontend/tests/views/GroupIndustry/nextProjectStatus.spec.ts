import { nextProjectStatus } from '@/views/GroupIndustry/nextProjectStatus'

describe('nextProjectStatus', () => {
  it('moves a published project to in progress', () => {
    expect(nextProjectStatus('published')).toBe('in_progress')
  })

  it('moves an in progress project to selling', () => {
    expect(nextProjectStatus('in_progress')).toBe('selling')
  })

  it('moves a selling project to completed', () => {
    expect(nextProjectStatus('selling')).toBe('completed')
  })

  it('has no next status for a completed project', () => {
    expect(nextProjectStatus('completed')).toBeNull()
  })

  it('has no next status for a draft project', () => {
    expect(nextProjectStatus('draft')).toBeNull()
  })
})
