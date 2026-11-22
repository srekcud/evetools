import { createPinia, setActivePinia } from 'pinia'
import { useFactoryStore } from '@/stores/industry/factory'
import { callsTo, stubBackend } from '../backendStub'
import {
  CONTAINERS_URL,
  KNOWN_CONTAINER,
  PROJECT_STOCK,
  PROJECT_STOCK_URL,
  RAITARU_ALPHA,
  STATIONS_URL,
  UNKNOWN_CONTAINER,
  UNLOCATED_BETA,
} from '../industryFactoryFixtures'

const NEW_ITEM_ID = 1_132_401_000_099
const DECLARATION_REFUSAL = 'The container is not visible in the corporation assets'

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('industry factory store', () => {
  it('fills the stations and the project stock from the two read resources', async () => {
    stubBackend({ [`GET ${STATIONS_URL}`]: [RAITARU_ALPHA, UNLOCATED_BETA], [`GET ${PROJECT_STOCK_URL}`]: PROJECT_STOCK })
    const store = useFactoryStore()

    await store.fetchStations()
    await store.fetchProjectStock()

    expect(store.stations).toEqual([RAITARU_ALPHA, UNLOCATED_BETA])
    expect(store.projectStock).toEqual(PROJECT_STOCK)
  })

  it('declares a container with a POST, reads the list again and answers true', async () => {
    let declared = false
    const calls = stubBackend({
      [`POST ${CONTAINERS_URL}`]: () => {
        declared = true
        return KNOWN_CONTAINER
      },
      [`GET ${CONTAINERS_URL}`]: () => (declared ? [KNOWN_CONTAINER] : []),
    })
    const store = useFactoryStore()

    const accepted = await store.declareContainer(NEW_ITEM_ID)

    expect(accepted).toBe(true)
    expect(callsTo(calls, 'POST', CONTAINERS_URL).map(call => call.body)).toEqual([{ itemId: NEW_ITEM_ID }])
    expect(callsTo(calls, 'GET', CONTAINERS_URL)).toHaveLength(1)
    expect(store.personalContainers).toEqual([KNOWN_CONTAINER])
    expect(store.declareError).toBeNull()
  })

  it('keeps the message of a 409 refusal in declareError and answers false', async () => {
    vi.stubGlobal('fetch', vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => init?.method === 'POST'
      ? new Response(JSON.stringify({ error: DECLARATION_REFUSAL }), { status: 409, headers: { 'Content-Type': 'application/json' } })
      : new Response(JSON.stringify([KNOWN_CONTAINER]), { status: 200, headers: { 'Content-Type': 'application/json' } })))
    const store = useFactoryStore()

    const accepted = await store.declareContainer(NEW_ITEM_ID)

    expect(accepted).toBe(false)
    expect(store.declareError).toBe(DECLARATION_REFUSAL)
  })

  it('removes a container with a DELETE then reads the list again', async () => {
    let removed = false
    const calls = stubBackend({
      [`DELETE ${CONTAINERS_URL}/${KNOWN_CONTAINER.id}`]: () => {
        removed = true
        return null
      },
      [`GET ${CONTAINERS_URL}`]: () => (removed ? [UNKNOWN_CONTAINER] : [KNOWN_CONTAINER, UNKNOWN_CONTAINER]),
    })
    const store = useFactoryStore()

    await store.removeContainer(KNOWN_CONTAINER.id)

    expect(callsTo(calls, 'DELETE', `${CONTAINERS_URL}/${KNOWN_CONTAINER.id}`)).toHaveLength(1)
    expect(store.personalContainers).toEqual([UNKNOWN_CONTAINER])
  })
})
