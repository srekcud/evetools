import { COMING_SOON_TABS, DETAIL_TABS, detailTabOfNextAction } from '@/views/GroupIndustry/projectDetailTabs'

describe('project detail tabs', () => {
  it('orders the tabs by the cockpit flow from the team to the journal', () => {
    expect([...DETAIL_TABS]).toEqual(['members', 'bom', 'contributions', 'invention', 'chain', 'planning', 'sales', 'distribution', 'journal'])
  })

  it('keeps the sourcing tab as the default, opens the chain tab for the production next actions and leaves the other deep links unchanged', () => {
    expect({
      materials: detailTabOfNextAction('materials'),
      production: detailTabOfNextAction('production'),
      contributions: detailTabOfNextAction('contributions'),
      sales: detailTabOfNextAction('sales'),
      finances: detailTabOfNextAction('finances'),
      members: detailTabOfNextAction('members'),
    }).toEqual({
      materials: 'bom',
      production: 'chain',
      contributions: 'contributions',
      sales: 'sales',
      finances: 'distribution',
      members: 'members',
    })
  })

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
