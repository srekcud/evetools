import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupChainStore } from '@/stores/group-industry/chain'
import { useGroupResearchStore } from '@/stores/group-industry/research'
import { useGroupStructureHealthStore } from '@/stores/group-industry/structureHealth'
import { deferred } from '../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar-batch'

function endpointsRequested(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint]) => String(endpoint).replace(`/industry/projects/${PROJECT_ID}`, ''))
}

function startAllThreeReads(): void {
  void useGroupChainStore().fetchChain(PROJECT_ID)
  void useGroupResearchStore().fetchResearch(PROJECT_ID)
  void useGroupStructureHealthStore().fetchStructureHealth(PROJECT_ID)
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('project detail reads of the chain tab', () => {
  it('asks for the three endpoints one after the other, the chain first', async () => {
    const pending = [deferred<unknown>(), deferred<unknown>(), deferred<unknown>()]
    pending.forEach(read => vi.mocked(apiRequest).mockReturnValueOnce(read.promise))
    const requestedAfter: string[][] = []

    startAllThreeReads()
    await flushPromises()
    requestedAfter.push(endpointsRequested())
    const answers: unknown[] = [{ nodes: [] }, { rows: [] }, { rows: [] }]
    for (const [index, read] of pending.entries()) {
      read.resolve(answers[index])
      await flushPromises()
      requestedAfter.push(endpointsRequested())
    }

    expect(requestedAfter).toEqual([
      ['/chain'],
      ['/chain', '/research'],
      ['/chain', '/research', '/structure-health'],
      ['/chain', '/research', '/structure-health'],
    ])
  })

  it('keeps every section in its loading state while it waits for its turn', async () => {
    const chainRead = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValueOnce(chainRead.promise).mockResolvedValue({ rows: [] })

    startAllThreeReads()
    const whileTheChainLoads = {
      research: useGroupResearchStore().loading,
      health: useGroupStructureHealthStore().loading,
    }
    chainRead.resolve({ nodes: [] })
    await flushPromises()

    expect(whileTheChainLoads).toEqual({ research: true, health: true })
    expect({
      research: useGroupResearchStore().loading,
      health: useGroupStructureHealthStore().loading,
    }).toEqual({ research: false, health: false })
  })

  it('reads the following endpoints and reports the error of the one that failed', async () => {
    vi.mocked(apiRequest)
      .mockRejectedValueOnce(new Error('Maximum execution time of 30 seconds exceeded'))
      .mockResolvedValue({ rows: [] })

    startAllThreeReads()
    await flushPromises()

    expect(endpointsRequested()).toEqual(['/chain', '/research', '/structure-health'])
    expect(useGroupChainStore().error).toBe('Maximum execution time of 30 seconds exceeded')
    expect(useGroupResearchStore().error).toBeNull()
  })
})
