import { apiRequest } from '@/services/api'
import { useOpenWindow } from '@/composables/useOpenWindow'

vi.mock('@/services/api', () => ({ apiRequest: vi.fn() }))

const STATION_ID = 60003760
const CONTAINER_ID = 1050000000101

describe('useOpenWindow', () => {
  beforeEach(() => {
    vi.mocked(apiRequest).mockReset()
  })

  it('sets the destination through the waypoint endpoint', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ success: true, error: null })

    const succeeded = await useOpenWindow().openWaypoint(STATION_ID)

    expect(succeeded).toBe(true)
    expect(apiRequest).toHaveBeenCalledWith('/me/open-window/waypoint', {
      method: 'POST',
      body: JSON.stringify({ destinationId: STATION_ID }),
    })
  })

  it('reports a refused waypoint as a failure', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ success: false, error: 'missing scope' })

    expect(await useOpenWindow().openWaypoint(STATION_ID)).toBe(false)
  })

  it('reports a failed request as a failure', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('network'))

    expect(await useOpenWindow().openWaypoint(STATION_ID)).toBe(false)
  })

  it('keeps opening the info window through its own endpoint', async () => {
    vi.mocked(apiRequest).mockResolvedValue({ success: true, error: null })

    await useOpenWindow().openInfo(CONTAINER_ID)

    expect(apiRequest).toHaveBeenCalledWith('/me/open-window/info', {
      method: 'POST',
      body: JSON.stringify({ targetId: CONTAINER_ID }),
    })
  })
})
