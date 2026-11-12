import {
  isSourcingCollapsed,
  isSourcingComplete,
  readSourcingOverride,
  sourcingStorageKey,
  writeSourcingOverride,
} from '@/views/GroupIndustry/sourcingCollapse'

function memoryStorage(initial: Record<string, string> = {}): Storage {
  const entries = new Map(Object.entries(initial))
  return {
    getItem: key => entries.get(key) ?? null,
    setItem: (key, value) => {
      entries.set(key, value)
    },
  } as Storage
}

describe('sourcing completeness', () => {
  it('is complete when every material line is fulfilled', () => {
    expect(isSourcingComplete([{ isFulfilled: true }, { isFulfilled: true }])).toBe(true)
  })

  it('is not complete while one material line is still open', () => {
    expect(isSourcingComplete([{ isFulfilled: true }, { isFulfilled: false }])).toBe(false)
  })

  it('is not complete for a project without material line', () => {
    expect(isSourcingComplete([])).toBe(false)
  })
})

describe('sourcing collapse decision', () => {
  it.each([
    { complete: true, override: null, collapsed: true },
    { complete: true, override: 'open' as const, collapsed: false },
    { complete: true, override: 'closed' as const, collapsed: true },
    { complete: false, override: null, collapsed: false },
    { complete: false, override: 'closed' as const, collapsed: false },
  ])('complete=$complete override=$override gives collapsed=$collapsed', ({ complete, override, collapsed }) => {
    expect(isSourcingCollapsed(complete, override)).toBe(collapsed)
  })
})

describe('sourcing override storage', () => {
  it('keeps the override of each project apart', () => {
    const storage = memoryStorage()

    writeSourcingOverride(storage, 'project-a', 'open')

    expect({
      a: readSourcingOverride(storage, 'project-a'),
      b: readSourcingOverride(storage, 'project-b'),
    }).toEqual({ a: 'open', b: null })
  })

  it('ignores a stored value that is neither open nor closed', () => {
    const storage = memoryStorage({ [sourcingStorageKey('project-a')]: 'maybe' })

    expect(readSourcingOverride(storage, 'project-a')).toBeNull()
  })
})
