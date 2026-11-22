import { flowCollapsedStorageKey, flowStateSummary, readFlowCollapsed, writeFlowCollapsed } from '@/views/GroupIndustry/flowCollapse'
import { flowCard, flowGrid, flowRow } from '../../components/group-industry/projectFlowFixtures'

const PROJECT_ID = 'project-nyx-batch'

function storageHolding(value: string | null): Pick<Storage, 'getItem'> {
  return { getItem: () => value }
}

describe('flow section collapse memory', () => {
  it('reads a collapsed flow section and an expanded one back from the viewer storage', () => {
    expect({
      collapsed: readFlowCollapsed(storageHolding('closed'), PROJECT_ID),
      expanded: readFlowCollapsed(storageHolding('open'), PROJECT_ID),
    }).toEqual({ collapsed: true, expanded: false })
  })

  it('treats a missing or unreadable stored value as expanded', () => {
    expect({
      missing: readFlowCollapsed(storageHolding(null), PROJECT_ID),
      garbage: readFlowCollapsed(storageHolding('maybe'), PROJECT_ID),
    }).toEqual({ missing: false, garbage: false })
  })

  it('treats a storage that throws as expanded instead of failing', () => {
    const blocked = { getItem: () => { throw new DOMException('blocked', 'SecurityError') } }

    expect(readFlowCollapsed(blocked, PROJECT_ID)).toBe(false)
  })

  it('writes the state under a key of its own per project and survives a storage that throws', () => {
    const written: [string, string][] = []
    const storage = { setItem: (key: string, value: string) => { written.push([key, value]) } }
    const blocked = { setItem: () => { throw new DOMException('quota', 'QuotaExceededError') } }

    writeFlowCollapsed(storage, PROJECT_ID, true)
    writeFlowCollapsed(storage, 'project-other', false)

    expect(() => writeFlowCollapsed(blocked, PROJECT_ID, true)).not.toThrow()
    expect(written).toEqual([[flowCollapsedStorageKey(PROJECT_ID), 'closed'], [flowCollapsedStorageKey('project-other'), 'open']])
    expect(flowCollapsedStorageKey(PROJECT_ID)).not.toBe(flowCollapsedStorageKey('project-other'))
  })
})

describe('flow summary', () => {
  it('counts the cards of every row and column per state and leaves out the states without a card', () => {
    const grid = flowGrid([
      flowRow({ key: 'azbel' }, {
        toBring: [flowCard('toBring', { typeName: 'Zydrine' }), flowCard('inTransit', { typeName: 'Mexallon' })],
        inProduction: [flowCard('inProduction', { typeName: 'Ishtar' })],
      }),
      flowRow({ key: 'jita', kind: 'market' }, {
        toBring: [flowCard('toBuy', { typeName: 'Tritanium' }), flowCard('toBuy', { typeName: 'Pyerite' })],
        done: [flowCard('sold', { typeName: 'Nyx' })],
      }),
    ])

    expect(flowStateSummary(grid)).toEqual([
      { state: 'toBring', count: 1 },
      { state: 'inTransit', count: 1 },
      { state: 'inProduction', count: 1 },
      { state: 'toBuy', count: 2 },
      { state: 'sold', count: 1 },
    ])
  })

  it('is empty for a grid without a card', () => {
    expect(flowStateSummary(flowGrid([flowRow({ key: 'azbel' })]))).toEqual([])
  })
})
