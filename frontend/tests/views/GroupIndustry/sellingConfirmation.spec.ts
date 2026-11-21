import { bomLine } from './projectCockpitFixtures'
import { unfinishedLinesOf } from '@/views/GroupIndustry/sellingConfirmation'

describe('unfinishedLinesOf', () => {
  it('splits the lines still open into uncovered materials and undelivered jobs', () => {
    const lines = [
      bomLine({ id: 'tritanium', typeId: 34, typeName: 'Tritanium', remainingQuantity: 400 }),
      bomLine({ id: 'pyerite', typeId: 35, typeName: 'Pyerite', remainingQuantity: 0, isFulfilled: true }),
      bomLine({ id: 'hull', typeId: 23_913, typeName: 'Nyx hull', isJob: true, remainingQuantity: 2 }),
      bomLine({ id: 'plating', typeId: 11_000, typeName: 'Plating', isJob: true, remainingQuantity: 0, isFulfilled: true }),
    ]

    const unfinished = unfinishedLinesOf(lines)

    expect({
      uncoveredMaterials: unfinished.uncoveredMaterials.map(line => line.id),
      undeliveredJobs: unfinished.undeliveredJobs.map(line => line.id),
    }).toEqual({ uncoveredMaterials: ['tritanium'], undeliveredJobs: ['hull'] })
  })

  it('finds nothing open when every line is fulfilled or there is no line', () => {
    const fulfilled = [
      bomLine({ id: 'pyerite', typeId: 35, typeName: 'Pyerite', isFulfilled: true }),
      bomLine({ id: 'plating', typeId: 11_000, typeName: 'Plating', isJob: true, isFulfilled: true }),
    ]

    expect([unfinishedLinesOf(fulfilled), unfinishedLinesOf([])]).toEqual([
      { uncoveredMaterials: [], undeliveredJobs: [] },
      { uncoveredMaterials: [], undeliveredJobs: [] },
    ])
  })
})
