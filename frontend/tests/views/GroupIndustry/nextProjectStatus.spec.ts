import { nextProjectStatuses } from '@/views/GroupIndustry/nextProjectStatus'

describe('nextProjectStatuses', () => {
  it('offers publishing or starting a draft project', () => {
    expect(nextProjectStatuses('draft')).toEqual(['published', 'in_progress'])
  })

  it('moves a published project to in progress', () => {
    expect(nextProjectStatuses('published')).toEqual(['in_progress'])
  })

  it('moves an in progress project to selling', () => {
    expect(nextProjectStatuses('in_progress')).toEqual(['selling'])
  })

  it('moves a selling project to completed', () => {
    expect(nextProjectStatuses('selling')).toEqual(['completed'])
  })

  it('has no next status for a completed project', () => {
    expect(nextProjectStatuses('completed')).toEqual([])
  })
})
