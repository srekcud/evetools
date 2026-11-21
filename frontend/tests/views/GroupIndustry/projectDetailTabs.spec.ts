import { COMING_SOON_TABS, DETAIL_TABS, detailTabOfNextAction, detailTabOfPhase, openingTabOf } from '@/views/GroupIndustry/projectDetailTabs'

describe('project detail tabs', () => {
  it('orders the tabs by the cockpit flow from the team to the journal', () => {
    expect([...DETAIL_TABS]).toEqual(['members', 'bom', 'contributions', 'invention', 'chain', 'planning', 'sales', 'distribution', 'journal'])
  })

  it('keeps the sourcing tab as the default and the next action deep links unchanged', () => {
    expect({
      materials: detailTabOfNextAction('materials'),
      contributions: detailTabOfNextAction('contributions'),
      sales: detailTabOfNextAction('sales'),
      finances: detailTabOfNextAction('finances'),
      members: detailTabOfNextAction('members'),
    }).toEqual({
      materials: 'bom',
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

  it('maps the supply, produce, sell and close phases to their tab and the prepare phase to none', () => {
    expect({
      prepare: detailTabOfPhase('prepare'),
      supply: detailTabOfPhase('supply'),
      produce: detailTabOfPhase('produce'),
      sell: detailTabOfPhase('sell'),
      close: detailTabOfPhase('close'),
    }).toEqual({ prepare: null, supply: 'bom', produce: 'chain', sell: 'sales', close: 'distribution' })
  })

  it('opens on the tab of the current phase, the sourcing tab without a current phase and the finances tab once everything is done', () => {
    const rail = (states: string[]) => ['prepare', 'supply', 'produce', 'sell', 'close'].map((key, index) => ({
      key,
      state: states[index],
      counter: { done: 0, total: 1 },
      blockedReason: null,
    })) as never
    expect({
      sell: openingTabOf(rail(['done', 'done', 'done', 'current', 'upcoming'])),
      none: openingTabOf(rail(['upcoming', 'upcoming', 'upcoming', 'upcoming', 'upcoming'])),
      allDone: openingTabOf(rail(['done', 'done', 'done', 'done', 'done'])),
    }).toEqual({ sell: 'sales', none: 'bom', allDone: 'distribution' })
  })
})
