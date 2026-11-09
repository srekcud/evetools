import { buildClosingChecks } from '@/components/group-industry/closingChecks'
import { closingChecks } from './projectSettlementFixtures'

describe('buildClosingChecks', () => {
  it('passes every check of a project with nothing left to do', () => {
    expect(buildClosingChecks(closingChecks())).toEqual([
      { key: 'pendingContributions', ok: true, count: 0 },
      { key: 'unsoldProducts', ok: true, products: [] },
      { key: 'finalMargin', ok: true, marginPercent: 55 },
      { key: 'transfers', ok: true, received: 2, total: 2 },
    ])
  })

  it('flags the contributions still waiting for a review', () => {
    expect(buildClosingChecks(closingChecks({ pendingContributionCount: 3 }))[0]).toEqual({ key: 'pendingContributions', ok: false, count: 3 })
  })

  it('lists every unsold item with its remaining quantity', () => {
    const products = [
      { typeId: 2185, typeName: 'Hammerhead II', quantity: 20 },
      { typeId: 2206, typeName: 'Hornet II', quantity: 5 },
    ]

    expect(buildClosingChecks(closingChecks({ unsoldProducts: products }))[1]).toEqual({ key: 'unsoldProducts', ok: false, products })
  })

  it('fails the margin check, never shows zero, when the final margin is unknown', () => {
    expect(buildClosingChecks(closingChecks({ marginPercent: null }))[2]).toEqual({ key: 'finalMargin', ok: false, marginPercent: null })
  })

  it('fails the transfers check while some transfer is not ticked', () => {
    expect(buildClosingChecks(closingChecks({ transfersTotal: 3, transfersReceived: 1 }))[3]).toEqual({ key: 'transfers', ok: false, received: 1, total: 3 })
  })

  it('fails the transfers check when the transfers cannot be computed yet', () => {
    expect(buildClosingChecks(closingChecks({ transfersTotal: null, transfersReceived: null }))[3]).toEqual({ key: 'transfers', ok: false, received: null, total: null })
  })

  it('passes the transfers check of a project that needs no transfer', () => {
    expect(buildClosingChecks(closingChecks({ transfersTotal: 0, transfersReceived: 0 }))[3]).toEqual({ key: 'transfers', ok: true, received: 0, total: 0 })
  })
})
