import { refreshesVisibleOn } from '@/views/GroupIndustry/groupProjectRefreshes'

describe('refreshes of the Finances tab when a sale changes', () => {
  it.each(['sale_recorded', 'sale_updated', 'sale_deleted'])('refreshes the sales and the distribution on the distribution tab on %s', (action) => {
    expect(refreshesVisibleOn([action], 'distribution')).toEqual(expect.arrayContaining(['sales', 'distribution']))
  })

  it('refreshes the sales and the sale suggestions on the sales tab on sale_recorded', () => {
    expect(refreshesVisibleOn(['sale_recorded'], 'sales')).toEqual(expect.arrayContaining(['sales', 'saleSuggestions']))
  })

  it('refreshes neither the sales nor the distribution on the bom tab on sale_recorded', () => {
    const refreshes = refreshesVisibleOn(['sale_recorded'], 'bom')

    expect([refreshes.includes('sales'), refreshes.includes('distribution')]).toEqual([false, false])
  })

  it('does not refresh the sale suggestions on the distribution tab on sale_recorded', () => {
    expect(refreshesVisibleOn(['sale_recorded'], 'distribution')).not.toContain('saleSuggestions')
  })

  it('refreshes neither the sales, the sale suggestions, the distribution, the settlement nor the contributions when no section is open', () => {
    const refreshes = refreshesVisibleOn(['sale_recorded', 'contribution_valued', 'transfer_received'], null)

    expect(refreshes).toEqual(expect.arrayContaining(['bom', 'cockpit', 'projectDetail']))
    expect(refreshes.filter(refresh => ['sales', 'saleSuggestions', 'distribution', 'settlement', 'contributions', 'activity'].includes(refresh))).toEqual([])
  })
})
