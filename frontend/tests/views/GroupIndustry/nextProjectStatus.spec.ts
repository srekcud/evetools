import { headerStatuses, nextProjectStatuses } from '@/views/GroupIndustry/nextProjectStatus'

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

  it('moves a personal use project in progress straight to completed', () => {
    expect({
      personalUse: nextProjectStatuses('in_progress', true),
      soldProject: nextProjectStatuses('in_progress', false),
    }).toEqual({
      personalUse: ['completed'],
      soldProject: ['selling'],
    })
  })

  it('moves a selling project to completed', () => {
    expect(nextProjectStatuses('selling')).toEqual(['completed'])
  })

  it('has no next status for a completed project', () => {
    expect(nextProjectStatuses('completed')).toEqual([])
  })
})

describe('headerStatuses', () => {
  it('offers publishing a draft project only when it is not private', () => {
    expect({
      shared: headerStatuses('draft', false, null),
      private: headerStatuses('draft', true, null),
    }).toEqual({
      shared: ['published', 'in_progress'],
      private: ['in_progress'],
    })
  })

  it('leaves the transition carried by the next action card out of the header', () => {
    expect({
      sharedDraft: headerStatuses('draft', false, 'in_progress'),
      privateDraft: headerStatuses('draft', true, 'in_progress'),
      inProgress: headerStatuses('in_progress', false, 'selling'),
      selling: headerStatuses('selling', true, 'completed'),
    }).toEqual({
      sharedDraft: ['published'],
      privateDraft: [],
      inProgress: [],
      selling: [],
    })
  })

  it('keeps the transition in the header when the card carries none or another one', () => {
    expect({
      published: headerStatuses('published', false, null),
      inProgress: headerStatuses('in_progress', false, null),
      selling: headerStatuses('selling', false, null),
      completed: headerStatuses('completed', false, null),
    }).toEqual({
      published: ['in_progress'],
      inProgress: ['selling'],
      selling: ['completed'],
      completed: [],
    })
  })

  it('offers completing a personal use project in progress and leaves out the transition carried by the next action card', () => {
    expect({
      withoutCard: headerStatuses('in_progress', false, null, true),
      withTheCompleteCard: headerStatuses('in_progress', false, 'completed', true),
      soldProject: headerStatuses('in_progress', false, null, false),
    }).toEqual({
      withoutCard: ['completed'],
      withTheCompleteCard: [],
      soldProject: ['selling'],
    })
  })
})
