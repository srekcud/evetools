import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupBuyOrBuildStore } from '@/stores/group-industry/buyOrBuild'
import { useGroupChainStore } from '@/stores/group-industry/chain'
import { useGroupResearchStore } from '@/stores/group-industry/research'
import { useGroupStructureHealthStore } from '@/stores/group-industry/structureHealth'
import { useGroupStructuresStore } from '@/stores/group-industry/structures'
import { deferred } from '../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'

function endpointsRequested(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint]) => String(endpoint).replace(`/industry/projects/${PROJECT_ID}`, ''))
}

function startAllFiveReads(): void {
  void useGroupChainStore().fetchChain(PROJECT_ID)
  void useGroupBuyOrBuildStore().fetchBuyOrBuild(PROJECT_ID)
  void useGroupResearchStore().fetchResearch(PROJECT_ID)
  void useGroupStructuresStore().fetchStructures(PROJECT_ID)
  void useGroupStructureHealthStore().fetchStructureHealth(PROJECT_ID)
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('project detail reads of the chain tab', () => {
  it('asks for the five endpoints one after the other, the chain first', async () => {
    const pending = [deferred<unknown>(), deferred<unknown>(), deferred<unknown>(), deferred<unknown>(), deferred<unknown>()]
    pending.forEach(read => vi.mocked(apiRequest).mockReturnValueOnce(read.promise))
    const requestedAfter: string[][] = []

    startAllFiveReads()
    await flushPromises()
    requestedAfter.push(endpointsRequested())
    const answers: unknown[] = [{ nodes: [] }, { rows: [], importIskPerCubicMeterJump: 150 }, { rows: [] }, { groups: [] }, { rows: [] }]
    for (const [index, read] of pending.entries()) {
      read.resolve(answers[index])
      await flushPromises()
      requestedAfter.push(endpointsRequested())
    }

    expect(requestedAfter).toEqual([
      ['/chain'],
      ['/chain', '/buy-or-build'],
      ['/chain', '/buy-or-build', '/research'],
      ['/chain', '/buy-or-build', '/research', '/structures'],
      ['/chain', '/buy-or-build', '/research', '/structures', '/structure-health'],
      ['/chain', '/buy-or-build', '/research', '/structures', '/structure-health'],
    ])
  })

  it('keeps every section in its loading state while it waits for its turn', async () => {
    const chainRead = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValueOnce(chainRead.promise).mockResolvedValue({ rows: [], groups: [], importIskPerCubicMeterJump: 150 })

    startAllFiveReads()
    const whileTheChainLoads = {
      buyOrBuild: useGroupBuyOrBuildStore().loading,
      research: useGroupResearchStore().loading,
      structures: useGroupStructuresStore().loading,
      health: useGroupStructureHealthStore().loading,
    }
    chainRead.resolve({ nodes: [] })
    await flushPromises()

    expect(whileTheChainLoads).toEqual({ buyOrBuild: true, research: true, structures: true, health: true })
    expect({
      buyOrBuild: useGroupBuyOrBuildStore().loading,
      research: useGroupResearchStore().loading,
      structures: useGroupStructuresStore().loading,
      health: useGroupStructureHealthStore().loading,
    }).toEqual({ buyOrBuild: false, research: false, structures: false, health: false })
  })

  it('reads the following endpoints and reports the error of the one that failed', async () => {
    vi.mocked(apiRequest)
      .mockRejectedValueOnce(new Error('Maximum execution time of 30 seconds exceeded'))
      .mockResolvedValue({ rows: [], groups: [], importIskPerCubicMeterJump: 150 })

    startAllFiveReads()
    await flushPromises()

    expect(endpointsRequested()).toEqual(['/chain', '/buy-or-build', '/research', '/structures', '/structure-health'])
    expect(useGroupChainStore().error).toBe('Maximum execution time of 30 seconds exceeded')
    expect(useGroupBuyOrBuildStore().error).toBeNull()
  })
})
