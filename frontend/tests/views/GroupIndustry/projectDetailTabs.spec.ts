import { COMING_SOON_TABS, DETAIL_TABS } from '@/views/GroupIndustry/projectDetailTabs'

describe('project detail tabs', () => {
  it('orders the tabs by the cockpit flow from the team to the journal', () => {
    expect([...DETAIL_TABS]).toEqual(['members', 'bom', 'contributions', 'invention', 'chain', 'sales', 'distribution', 'journal'])
  })

  it('exposes the journal and the invention as working tabs, with no per-character planning tab and keeps no coming soon tab', () => {
    expect({
      planningIsATab: (DETAIL_TABS as string[]).includes('planning'),
      journalIsAWorkingTab: (DETAIL_TABS as string[]).includes('journal'),
      inventionIsAWorkingTab: (DETAIL_TABS as string[]).includes('invention'),
      comingSoonTabs: [...COMING_SOON_TABS] as string[],
    }).toEqual({
      planningIsATab: false,
      journalIsAWorkingTab: true,
      inventionIsAWorkingTab: true,
      comingSoonTabs: [],
    })
  })
})
