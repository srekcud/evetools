import { COMING_SOON_TABS, DETAIL_TABS } from '@/views/GroupIndustry/projectDetailTabs'

describe('project detail tabs', () => {
  it('exposes the planning as a working tab and keeps only the invention and the journal as coming soon tabs', () => {
    expect({
      planningIsAWorkingTab: (DETAIL_TABS as string[]).includes('planning'),
      comingSoonTabs: [...COMING_SOON_TABS] as string[],
    }).toEqual({
      planningIsAWorkingTab: true,
      comingSoonTabs: ['invention', 'journal'],
    })
  })
})
