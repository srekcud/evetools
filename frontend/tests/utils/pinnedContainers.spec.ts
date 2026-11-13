import { groupPinnedEntries, locateContainer, projectPinnedFirst } from '@/utils/pinnedContainers'
import type { Asset, PinnedContainer } from '@/types'

function entry(overrides: Partial<PinnedContainer>): PinnedContainer {
  return {
    id: '1',
    itemId: 1,
    name: 'Box',
    locationName: 'Jita IV - Moon 4',
    solarSystemName: 'Jita',
    divisionName: 'Reactions',
    itemCount: 3,
    found: true,
    projectId: null,
    projectName: null,
    ...overrides,
  }
}

function asset(itemId: number, overrides: Partial<Asset> = {}): Asset {
  return {
    id: `00000000-0000-4000-8000-${String(itemId).padStart(12, '0')}`,
    itemId,
    typeId: 17366,
    typeName: 'Giant Secure Container',
    categoryId: 2,
    quantity: 1,
    locationId: 60003760,
    locationName: 'Jita IV - Moon 4',
    locationType: 'station',
    locationFlag: 'CorpSAG2',
    divisionName: 'Reactions',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    isSingleton: true,
    canResolveItemName: false,
    cachedAt: '2026-11-13T11:00:00+00:00',
    ...overrides,
  }
}

describe('groupPinnedEntries', () => {
  it('puts project containers in one group per project, in the order of the entries, and the rest in manual', () => {
    const entries = [
      entry({ id: '1', itemId: 1, projectId: 'p1', projectName: 'Capital rush' }),
      entry({ id: '2', itemId: 2, projectId: 'p2', projectName: 'Joint build' }),
      entry({ id: '3', itemId: 3, projectId: 'p1', projectName: 'Capital rush' }),
      entry({ id: '4', itemId: 4 }),
    ]

    const grouped = groupPinnedEntries(entries)

    expect(grouped.projects.map(group => [group.projectId, group.projectName, group.entries.map(item => item.itemId)])).toEqual([
      ['p1', 'Capital rush', [1, 3]],
      ['p2', 'Joint build', [2]],
    ])
    expect(grouped.manual.map(item => item.itemId)).toEqual([4])
  })

  it('returns nothing for no entries', () => {
    expect(groupPinnedEntries([])).toEqual({ projects: [], manual: [] })
  })
})

describe('locateContainer', () => {
  it('gives the location and the container to open for a container stored in a hangar', () => {
    const assets = new Map([[10, asset(10)]])

    expect(locateContainer(10, assets)).toEqual({ locationName: 'Jita IV - Moon 4', containerItemIds: [10] })
  })

  it('opens every container on the way up when the container is nested', () => {
    const outer = asset(10)
    const inner = asset(11, { locationId: 10, locationType: 'item', locationName: 'Outer - Jita IV - Moon 4' })
    const assets = new Map([[10, outer], [11, inner]])

    expect(locateContainer(11, assets)).toEqual({ locationName: 'Jita IV - Moon 4', containerItemIds: [11, 10] })
  })

  it('returns null when the container is not in the loaded assets', () => {
    expect(locateContainer(99, new Map())).toBeNull()
  })
})

describe('projectPinnedFirst', () => {
  it('moves project containers first and keeps the order of the others', () => {
    const assets = [asset(1), asset(2), asset(3), asset(4)]

    expect(projectPinnedFirst(assets, new Set([3, 2])).map(item => item.itemId)).toEqual([2, 3, 1, 4])
  })

  it('returns the assets untouched when no container is pinned by a project', () => {
    const assets = [asset(1), asset(2)]

    expect(projectPinnedFirst(assets, new Set())).toEqual(assets)
  })
})
