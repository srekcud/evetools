import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import FactoryPersonalStock from '@/components/industry-factory/FactoryPersonalStock.vue'
import { useFactoryStore } from '@/stores/industry/factory'
import { callsTo } from '../../backendStub'
import { CONTAINERS_URL, KNOWN_CONTAINER, UNKNOWN_CONTAINER } from '../../industryFactoryFixtures'
import { mountFactory } from './mountFactory'

enableAutoUnmount(afterEach)

const ROWS = '[data-testid="factory-container-row"]'
const ITEMS = '[data-testid="factory-container-items"]'
const REMOVE = '[data-testid="factory-container-remove"]'
const DECLARE_INPUT = '[data-testid="factory-container-declare-input"]'
const DECLARE = '[data-testid="factory-container-declare"]'
const ERROR = '[data-testid="factory-container-error"]'
const NEW_ITEM_ID = 1_132_401_000_099
const REFUSAL = 'The container is not visible in the corporation assets'

async function mountContainers() {
  return mountFactory(FactoryPersonalStock, {
    answers: { [`GET ${CONTAINERS_URL}`]: [KNOWN_CONTAINER, UNKNOWN_CONTAINER] },
    seed: () => {
      useFactoryStore().personalContainers = [KNOWN_CONTAINER, UNKNOWN_CONTAINER]
    },
  })
}

function containerRows(wrapper: VueWrapper) {
  const rows = wrapper.findAll(ROWS)
  expect(rows).toHaveLength(2)
  return rows
}

describe('FactoryPersonalStock', () => {
  it('shows the item count of a known container and none for an unknown one, and removes a container with a DELETE', async () => {
    const { wrapper, calls } = await mountContainers()

    const [known, unknown] = containerRows(wrapper)
    expect(known.text()).toContain('Perso A')
    expect(known.text()).toContain('Reactions')
    expect(known.text()).toContain('Jita IV - Moon 4')
    expect(known.get(ITEMS).text()).toContain('7')
    expect(unknown.text()).toContain('Perso B')
    expect(unknown.find(ITEMS).exists()).toBe(false)
    expect(unknown.text()).not.toMatch(/\b0\b/)

    await known.get(REMOVE).trigger('click')
    await flushPromises()

    expect(callsTo(calls, 'DELETE', `${CONTAINERS_URL}/${KNOWN_CONTAINER.id}`)).toHaveLength(1)
  })

  it('declares the typed item id with a POST and shows the message of the API when it is refused', async () => {
    const { wrapper } = await mountContainers()
    containerRows(wrapper)
    const posted: unknown[] = []
    vi.stubGlobal('fetch', vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
      if (init?.method === 'POST') {
        posted.push(JSON.parse(String(init.body)))
        return new Response(JSON.stringify({ error: REFUSAL }), { status: 409, headers: { 'Content-Type': 'application/json' } })
      }
      return new Response(JSON.stringify([KNOWN_CONTAINER, UNKNOWN_CONTAINER]), { status: 200, headers: { 'Content-Type': 'application/json' } })
    }))

    await wrapper.get(DECLARE_INPUT).setValue(String(NEW_ITEM_ID))
    await wrapper.get(DECLARE).trigger('click')
    await flushPromises()

    expect(posted).toEqual([{ itemId: NEW_ITEM_ID }])
    expect(wrapper.get(ERROR).text()).toContain(REFUSAL)
  })
})
