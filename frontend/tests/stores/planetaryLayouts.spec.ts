import { createPinia, setActivePinia } from 'pinia'
import { apiRequest, ValidationError } from '@/services/api'
import { usePlanetaryLayoutsStore, type ColonyLayout } from '@/stores/planetaryLayouts'
import { COOLANT_LAYOUT, ROBOTICS_LAYOUT, VALID_GAME_TEXT } from '../planetaryLayoutsFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const LAYOUTS_ENDPOINT = '/planetary/layouts'

const IMPORTED_LAYOUT: ColonyLayout = { ...COOLANT_LAYOUT, id: 'layout-imported', name: 'Imported colony', createdAt: '2026-11-26T09:00:00+00:00' }

function rejectedWith(violations: Array<{ propertyPath: string, message: string }>) {
  return new ValidationError({ '@type': 'ConstraintViolationList', status: 422, violations, description: '', detail: '' })
}

function requestsOf(method: string) {
  return vi.mocked(apiRequest).mock.calls
    .filter(call => ((call[1] as RequestInit | undefined)?.method ?? 'GET') === method)
    .map(call => ({ endpoint: call[0], body: (call[1] as RequestInit | undefined)?.body === undefined ? undefined : JSON.parse(String((call[1] as RequestInit).body)) }))
}

async function storeHolding(layouts: ColonyLayout[], answers: Record<string, () => unknown> = {}) {
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string, options?: RequestInit) => {
    const answer = answers[`${options?.method ?? 'GET'} ${endpoint}`]
    if (answer !== undefined) return answer()
    if (endpoint === LAYOUTS_ENDPOINT && (options?.method ?? 'GET') === 'GET') return layouts
    return null
  }) as never)
  const store = usePlanetaryLayoutsStore()
  await store.load()
  vi.mocked(apiRequest).mockClear()
  return store
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('planetaryLayouts store', () => {
  it('loads my layouts with a GET and keeps them in the order of the response', async () => {
    vi.mocked(apiRequest).mockResolvedValue([ROBOTICS_LAYOUT, COOLANT_LAYOUT] as never)
    const store = usePlanetaryLayoutsStore()

    await store.load()

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0])).toEqual([LAYOUTS_ENDPOINT])
    expect(store.layouts.map(layout => layout.id)).toEqual(['layout-robotics', 'layout-coolant'])
  })

  it('posts the pasted text with the name, puts the created layout first and returns no error', async () => {
    const store = await storeHolding([COOLANT_LAYOUT], { [`POST ${LAYOUTS_ENDPOINT}`]: () => IMPORTED_LAYOUT })

    const errors = await store.importLayout(VALID_GAME_TEXT, 'Imported colony')

    expect(errors).toEqual([])
    expect(requestsOf('POST')).toEqual([{ endpoint: LAYOUTS_ENDPOINT, body: { template: VALID_GAME_TEXT, name: 'Imported colony' } }])
    expect(store.layouts.map(layout => layout.id)).toEqual(['layout-imported', 'layout-coolant'])
  })

  it('posts the text alone, without any name key, when no name is given', async () => {
    const store = await storeHolding([], { [`POST ${LAYOUTS_ENDPOINT}`]: () => IMPORTED_LAYOUT })

    await store.importLayout(VALID_GAME_TEXT)

    expect(requestsOf('POST')).toEqual([{ endpoint: LAYOUTS_ENDPOINT, body: { template: VALID_GAME_TEXT } }])
  })

  it('returns the path and code of every error of a 422 and adds nothing to the list', async () => {
    const store = await storeHolding([COOLANT_LAYOUT], {
      [`POST ${LAYOUTS_ENDPOINT}`]: () => {
        throw rejectedWith([
          { propertyPath: 'P[0].T', message: 'unknown_installation' },
          { propertyPath: 'CmdCtrLv', message: 'out_of_range' },
        ])
      },
    })

    const errors = await store.importLayout('{"CmdCtrLv":6}', 'Broken colony')

    expect(errors).toEqual([
      { path: 'P[0].T', code: 'unknown_installation' },
      { path: 'CmdCtrLv', code: 'out_of_range' },
    ])
    expect(store.layouts.map(layout => layout.id)).toEqual(['layout-coolant'])
  })

  it('renames with a PATCH carrying the name alone and swaps in the layout of the response', async () => {
    const renamed: ColonyLayout = { ...COOLANT_LAYOUT, name: 'Renamed colony' }
    const store = await storeHolding([ROBOTICS_LAYOUT, COOLANT_LAYOUT], { [`PATCH ${LAYOUTS_ENDPOINT}/layout-coolant`]: () => renamed })

    await store.rename('layout-coolant', 'Renamed colony')

    expect(requestsOf('PATCH')).toEqual([{ endpoint: `${LAYOUTS_ENDPOINT}/layout-coolant`, body: { name: 'Renamed colony' } }])
    expect(store.layouts.map(layout => [layout.id, layout.name])).toEqual([['layout-robotics', 'Robotics line'], ['layout-coolant', 'Renamed colony']])
  })

  it('deletes with a DELETE and removes only that layout from the list', async () => {
    const store = await storeHolding([ROBOTICS_LAYOUT, COOLANT_LAYOUT])

    await store.remove('layout-robotics')

    expect(requestsOf('DELETE')).toEqual([{ endpoint: `${LAYOUTS_ENDPOINT}/layout-robotics`, body: undefined }])
    expect(store.layouts.map(layout => layout.id)).toEqual(['layout-coolant'])
  })
})
