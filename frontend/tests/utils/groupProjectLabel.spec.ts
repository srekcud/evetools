import { describe, expect, it } from 'vitest'
import type { GroupProject } from '@/stores/group-industry/types'
import { groupProjectLabel } from '@/utils/groupProjectLabel'
import { projectDisplayNameOf } from '@/components/group-industry/projectDisplayName'

const VALKYRIE_II_TYPE_ID = 12_034
const RIFTER_TYPE_ID = 587
const MERLIN_TYPE_ID = 603
const VALKYRIE_LINES = 100
const RUNS_PER_VALKYRIE_LINE = 10

type LabelledProject = Pick<GroupProject, 'name' | 'items'>

function item(typeId: number, typeName: string, runs: number): GroupProject['items'][number] {
  return { typeId, typeName, meLevel: 10, teLevel: 20, runs } as GroupProject['items'][number]
}

function project(name: string | null, items: GroupProject['items']): LabelledProject {
  return { name, items } as LabelledProject
}

describe('groupProjectLabel', () => {
  it('returns the name of a named project, whatever its items', () => {
    const named = project('Fuel batch', [item(RIFTER_TYPE_ID, 'Rifter', 2), item(MERLIN_TYPE_ID, 'Merlin', 1)])

    expect(groupProjectLabel(named)).toBe('Fuel batch')
  })

  it('labels an unnamed project with one item by its runs and type', () => {
    expect(groupProjectLabel(project(null, [item(RIFTER_TYPE_ID, 'Rifter', 2)]))).toBe('2x Rifter')
  })

  it('sums the runs of the lines of the same type with a thousands separator', () => {
    const lines = Array.from({ length: VALKYRIE_LINES }, () => item(VALKYRIE_II_TYPE_ID, 'Valkyrie II', RUNS_PER_VALKYRIE_LINE))

    expect(groupProjectLabel(project(null, lines))).toBe('1,000x Valkyrie II')
  })

  it('shows the first type with the count of the other types', () => {
    const several = project(null, [item(RIFTER_TYPE_ID, 'Rifter', 2), item(MERLIN_TYPE_ID, 'Merlin', 1), item(VALKYRIE_II_TYPE_ID, 'Valkyrie II', 4)])

    expect(groupProjectLabel(several)).toBe('2x Rifter +2')
  })

  it('counts a type once when its lines are not adjacent', () => {
    const interleaved = project(null, [
      item(VALKYRIE_II_TYPE_ID, 'Valkyrie II', 10),
      item(RIFTER_TYPE_ID, 'Rifter', 5),
      item(VALKYRIE_II_TYPE_ID, 'Valkyrie II', 10),
    ])

    expect(groupProjectLabel(interleaved)).toBe('20x Valkyrie II +1')
  })

  it('has no label for an unnamed project without items', () => {
    expect(groupProjectLabel(project(null, []))).toBe('')
  })
})

describe('projectDisplayNameOf', () => {
  it('gives the same label as groupProjectLabel', () => {
    const several = project(null, [item(RIFTER_TYPE_ID, 'Rifter', 2), item(MERLIN_TYPE_ID, 'Merlin', 1)])

    expect(projectDisplayNameOf(several)).toBe(groupProjectLabel(several))
  })
})
