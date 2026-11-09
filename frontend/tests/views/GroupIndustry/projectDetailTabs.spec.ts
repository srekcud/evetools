import { COMING_SOON_TABS, DETAIL_TABS } from '@/views/GroupIndustry/projectDetailTabs'

describe('project detail tabs', () => {
  it('exposes the planning and the journal as working tabs and keeps only the invention as a coming soon tab', () => {
    expect({
      planningIsAWorkingTab: (DETAIL_TABS as string[]).includes('planning'),
      journalIsAWorkingTab: (DETAIL_TABS as string[]).includes('journal'),
      comingSoonTabs: [...COMING_SOON_TABS] as string[],
    }).toEqual({
      planningIsAWorkingTab: true,
      journalIsAWorkingTab: true,
      comingSoonTabs: ['invention'],
    })
  })
})
