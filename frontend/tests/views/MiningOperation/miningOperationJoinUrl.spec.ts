import router from '@/router'
import { miningOperationJoinUrl } from '@/views/MiningOperation/miningOperationJoinUrl'

const ORIGIN = 'https://evetools.example'
const JOIN_CODE = '7f3a9c2e1b'

describe('miningOperationJoinUrl', () => {
  it('builds the invitation link from the origin and the join code', () => {
    expect(miningOperationJoinUrl(ORIGIN, JOIN_CODE)).toBe('https://evetools.example/mining-operations/join/7f3a9c2e1b')
  })

  it('resolves the invitation path to the mining operation join route with the join code', () => {
    const resolved = router.resolve(`/mining-operations/join/${JOIN_CODE}`)

    expect(resolved.name).toBe('mining-operation-join')
    expect(resolved.params.code).toBe('7f3a9c2e1b')
  })

  it('reaches the available operations through the tab of the list, not through a dedicated route', () => {
    expect(router.resolve('/mining-operations?tab=available').name).toBe('mining-operations')
    expect(router.resolve('/mining-operations/available').name).not.toBe('mining-operation-detail')
  })
})
