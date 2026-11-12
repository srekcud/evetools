import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import fr from '@/i18n/locales/fr.json'
import AssetLocationGroup from '@/components/assets/AssetLocationGroup.vue'
import type { Asset } from '@/types'
import { awaiting, deferred } from '../../deferred'

const JITA_STATION = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant'
const ESI_REJECTION_MESSAGE = 'Requested item is not a nameable asset'
const CONTAINER_ITEM_ID = 1050000784011

interface ItemNameCall {
  url: string
  method: string
}

function asset(overrides: Partial<Asset>): Asset {
  return {
    id: '00000000-0000-4000-8000-000000784010',
    itemId: 1050000784010,
    typeId: 587,
    typeName: 'Rifter',
    categoryId: 6,
    quantity: 1,
    locationId: 60003760,
    locationName: JITA_STATION,
    locationType: 'station',
    locationFlag: 'Hangar',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    itemName: null,
    isSingleton: true,
    canResolveItemName: true,
    divisionName: null,
    cachedAt: '2026-11-12T10:00:00+00:00',
    ...overrides,
  }
}

const unnamedShip = asset({})

const tritaniumStack = asset({
  id: '00000000-0000-4000-8000-000000784012',
  itemId: 1050000784012,
  typeId: 34,
  typeName: 'Tritanium',
  categoryId: 4,
  quantity: 8800,
  isSingleton: false,
  canResolveItemName: false,
})

const stationContainer = asset({
  id: '00000000-0000-4000-8000-000000784011',
  itemId: CONTAINER_ITEM_ID,
  typeId: 17366,
  typeName: 'Station Container',
  categoryId: 2,
  itemName: 'Project Box',
  canResolveItemName: false,
})

const unnamedVentureInContainer = asset({
  id: '00000000-0000-4000-8000-000000784013',
  itemId: 1050000784013,
  typeId: 32880,
  typeName: 'Venture',
  locationId: CONTAINER_ITEM_ID,
  locationType: 'item',
  locationFlag: 'Unlocked',
})

function endpointOf(row: Asset): string {
  return `/api/me/assets/${row.id}/item-name`
}

function resolveButtonOf(wrapper: ReturnType<typeof mountGroup>, row: Asset) {
  return wrapper.find(`[data-testid="resolve-item-name-${row.id}"]`)
}

async function clickResolveButton(wrapper: ReturnType<typeof mountGroup>, row: Asset): Promise<void> {
  const button = resolveButtonOf(wrapper, row)
  expect(button.exists()).toBe(true)
  await button.trigger('click')
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function stubItemNameEndpoint(answer: () => Promise<unknown>): ItemNameCall[] {
  const calls: ItemNameCall[] = []
  vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    calls.push({ url: String(input), method: init?.method?.toUpperCase() ?? 'GET' })
    return (await answer()) as Response
  }))
  return calls
}

function mountGroup(assets: Asset[], containerContents: Map<number, Asset[]> = new Map()) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const i18n = createI18n({ legacy: false, locale: 'fr', messages: { fr } })
  return mount(AssetLocationGroup, {
    global: { plugins: [i18n, pinia], stubs: { OpenInGameButton: true } },
    props: {
      location: {
        locationId: 60003760,
        locationName: JITA_STATION,
        solarSystemName: 'Jita',
        assets,
        totalQuantity: assets.reduce((sum, a) => sum + a.quantity, 0),
      },
      expandedContainers: new Set(containerContents.keys()),
      collapsedLocations: new Set<string>(),
      containerContents,
    },
  })
}

function expectTranslated(text: string | undefined): void {
  expect(text).toBeTruthy()
  expect(text).not.toMatch(/^\w+(\.\w+)+$/)
}

function flattenedText(wrapper: ReturnType<typeof mountGroup>): string {
  return wrapper.text().replace(/\s+/g, ' ')
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('AssetLocationGroup item name resolution', () => {
  it('shows a labelled resolve button only on the rows whose item name can be resolved', () => {
    const wrapper = mountGroup([unnamedShip, tritaniumStack])

    const button = resolveButtonOf(wrapper, unnamedShip)
    expect(button.exists()).toBe(true)
    expectTranslated(button.attributes('aria-label'))
    expectTranslated(button.attributes('title'))
    expect(resolveButtonOf(wrapper, tritaniumStack).exists()).toBe(false)
  })

  it('shows the resolve button on an unnamed item inside an expanded container', () => {
    const wrapper = mountGroup([stationContainer], new Map([[CONTAINER_ITEM_ID, [unnamedVentureInContainer]]]))

    expect(resolveButtonOf(wrapper, unnamedVentureInContainer).exists()).toBe(true)
    expect(resolveButtonOf(wrapper, stationContainer).exists()).toBe(false)
  })

  it('posts to the item name endpoint of the row and disables its button while the answer is pending', async () => {
    const pending = deferred<Response>()
    const calls = stubItemNameEndpoint(awaiting(pending))
    const wrapper = mountGroup([unnamedShip, tritaniumStack])

    await clickResolveButton(wrapper, unnamedShip)
    await flushPromises()

    expect(calls).toEqual([{ url: endpointOf(unnamedShip), method: 'POST' }])
    expect(resolveButtonOf(wrapper, unnamedShip).attributes('disabled')).toBeDefined()

    pending.resolve(jsonResponse({ id: unnamedShip.id, status: 'named', itemName: 'Hauler One' }))
    await flushPromises()
    expect(calls).toHaveLength(1)
  })

  it('labels the row with its custom name and its type name once named, without another request', async () => {
    const calls = stubItemNameEndpoint(() => Promise.resolve(jsonResponse({ id: unnamedShip.id, status: 'named', itemName: 'Hauler One' })))
    const wrapper = mountGroup([unnamedShip, tritaniumStack])

    await clickResolveButton(wrapper, unnamedShip)
    await flushPromises()

    expect(flattenedText(wrapper)).toContain('Hauler One (Rifter)')
    expect(resolveButtonOf(wrapper, unnamedShip).exists()).toBe(false)
    expect(calls).toHaveLength(1)
  })

  it('labels an item of an expanded container with its custom name once named', async () => {
    stubItemNameEndpoint(() => Promise.resolve(jsonResponse({ id: unnamedVentureInContainer.id, status: 'named', itemName: 'Gas Skiff' })))
    const wrapper = mountGroup([stationContainer], new Map([[CONTAINER_ITEM_ID, [unnamedVentureInContainer]]]))

    await clickResolveButton(wrapper, unnamedVentureInContainer)
    await flushPromises()

    expect(flattenedText(wrapper)).toContain('Gas Skiff (Venture)')
    expect(resolveButtonOf(wrapper, unnamedVentureInContainer).exists()).toBe(false)
  })

  it('replaces the button by a muted text and keeps the type name when the item has no custom name in game', async () => {
    stubItemNameEndpoint(() => Promise.resolve(jsonResponse({ id: unnamedShip.id, status: 'unnamed', itemName: null })))
    const wrapper = mountGroup([unnamedShip])

    await clickResolveButton(wrapper, unnamedShip)
    await flushPromises()

    expectTranslated(wrapper.find(`[data-testid="item-name-unnamed-${unnamedShip.id}"]`).text())
    expect(resolveButtonOf(wrapper, unnamedShip).exists()).toBe(false)
    expect(flattenedText(wrapper)).not.toContain('(Rifter)')
  })

  it('shows the ESI message on the row and gives the button back when the resolution fails', async () => {
    stubItemNameEndpoint(() => Promise.resolve(jsonResponse({ detail: ESI_REJECTION_MESSAGE }, 502)))
    const wrapper = mountGroup([unnamedShip])

    await clickResolveButton(wrapper, unnamedShip)
    await flushPromises()

    expect(wrapper.find(`[data-testid="item-name-error-${unnamedShip.id}"]`).text()).toContain(ESI_REJECTION_MESSAGE)
    expect(resolveButtonOf(wrapper, unnamedShip).exists()).toBe(true)
    expect(resolveButtonOf(wrapper, unnamedShip).attributes('disabled')).toBeUndefined()
  })
})
