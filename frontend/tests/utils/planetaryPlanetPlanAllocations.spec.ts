import { DEFAULT_SETTINGS, type PlannedProduction } from '@/utils/planetaryPlanner'
import { buildPlanetPlan } from '@/utils/planetaryPlanets'
import { BROADCAST_NODE, PLANNER_ACCOUNT, PLANNER_CATALOG, ROBOTICS } from '../planetaryPlannerFixtures'

function planOf(...targets: [number, number][]) {
  const productions: PlannedProduction[] = targets.map(([typeId, targetPerDay]) => ({ typeId, targetPerDay }))
  return buildPlanetPlan(productions, PLANNER_CATALOG, {}, DEFAULT_SETTINGS, PLANNER_ACCOUNT, {})
}

describe('buildPlanetPlan allocations between productions', () => {
  it('puts the whole addition on free slots when more slots are free than the production adds: 1 P3 and 2 P2 factories with 12 and 9 slots free', () => {
    const result = planOf([ROBOTICS, 72], [ROBOTICS, 24])

    expect(result.addedByProduction).toEqual([2, 0])
    expect(result.allocations[1]).toEqual([
      { tier: 'P3', onFreeSlots: 1, onNewPlanets: 0, freeRemaining: 11 },
      { tier: 'P2', onFreeSlots: 2, onNewPlanets: 0, freeRemaining: 7 },
    ])
  })

  it('opens new planets for a tier the previous production did not use, and fills the free slots of the tiers it did use', () => {
    const result = planOf([ROBOTICS, 72], [BROADCAST_NODE, 24])

    expect(result.addedByProduction).toEqual([2, 1])
    expect(result.allocations).toEqual([
      [
        { tier: 'P3', onFreeSlots: 0, onNewPlanets: 1, freeRemaining: 12 },
        { tier: 'P2', onFreeSlots: 0, onNewPlanets: 4, freeRemaining: 9 },
      ],
      [
        { tier: 'P4', onFreeSlots: 0, onNewPlanets: 1, freeRemaining: 4 },
        { tier: 'P3', onFreeSlots: 2, onNewPlanets: 0, freeRemaining: 10 },
        { tier: 'P2', onFreeSlots: 8, onNewPlanets: 0, freeRemaining: 1 },
      ],
    ])
  })

  it('uses every free slot then opens planets for the rest when a different product adds more than is free: 12 P3 on 12 free slots, 48 P2 on 9 free slots and 39 new', () => {
    const result = planOf([ROBOTICS, 72], [BROADCAST_NODE, 144])

    expect(result.addedByProduction).toEqual([2, 5])
    expect(result.allocations[1]).toEqual([
      { tier: 'P4', onFreeSlots: 0, onNewPlanets: 6, freeRemaining: 4 },
      { tier: 'P3', onFreeSlots: 12, onNewPlanets: 0, freeRemaining: 0 },
      { tier: 'P2', onFreeSlots: 9, onNewPlanets: 39, freeRemaining: 0 },
    ])
  })

  it('gives no line to a production that adds nothing: a second production of 1 per day fits the slack of the first', () => {
    const result = planOf([BROADCAST_NODE, 100], [BROADCAST_NODE, 1])

    expect(result.addedByProduction).toEqual([5, 0])
    expect(result.allocations[1]).toEqual([])
  })

  it('leaves out only the tiers a tiny third production does not add to: no P4 line, one factory on free slots for P3 and for P2', () => {
    const result = planOf([BROADCAST_NODE, 120], [BROADCAST_NODE, 60], [BROADCAST_NODE, 1])

    expect(result.addedByProduction).toEqual([6, 3, 0])
    expect(result.allocations[2]).toEqual([
      { tier: 'P3', onFreeSlots: 1, onNewPlanets: 0, freeRemaining: 10 },
      { tier: 'P2', onFreeSlots: 1, onNewPlanets: 0, freeRemaining: 4 },
    ])
  })

  it('takes the free slots left after the second production, not after the first, for a third production: 5 slots free for 12 P2 added', () => {
    const result = planOf([BROADCAST_NODE, 120], [BROADCAST_NODE, 60], [BROADCAST_NODE, 36])

    expect(result.addedByProduction).toEqual([6, 3, 1])
    expect(result.allocations[2]).toEqual([
      { tier: 'P4', onFreeSlots: 1, onNewPlanets: 0, freeRemaining: 1 },
      { tier: 'P3', onFreeSlots: 3, onNewPlanets: 0, freeRemaining: 8 },
      { tier: 'P2', onFreeSlots: 5, onNewPlanets: 7, freeRemaining: 6 },
    ])
  })
})
