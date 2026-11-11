import { COMING_SOON_TABS, DETAIL_TABS } from '@/views/GroupIndustry/projectDetailTabs'

describe('project detail tabs', () => {
  it('exposes the planning, the journal and the invention as working tabs and keeps no coming soon tab', () => {
    expect({
      planningIsAWorkingTab: (DETAIL_TABS as string[]).includes('planning'),
      journalIsAWorkingTab: (DETAIL_TABS as string[]).includes('journal'),
      inventionIsAWorkingTab: (DETAIL_TABS as string[]).includes('invention'),
      comingSoonTabs: [...COMING_SOON_TABS] as string[],
    }).toEqual({
      planningIsAWorkingTab: true,
      journalIsAWorkingTab: true,
      inventionIsAWorkingTab: true,
      comingSoonTabs: [],
    })
  })
})
