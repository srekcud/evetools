import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import MoonChunksTab from '@/components/ledger/MoonChunksTab.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'
import {
  CORPORATION_A,
  EXPLODED_CHUNK,
  NOW,
  READY_CHUNK,
  ROCK_HOLLOW_CHUNK,
  SYNCED_CORPORATION,
  corporation,
  response,
} from './moonChunksHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const EXTRACTIONS_PATH = '/mining/corporation-extractions'
const OPERATIONS_PATH = '/mining-operations'
const FOLLOWS_PATH = '/mining/followed-refineries'
const ILYRA_CHARACTER_ID = '0199a1f0-0000-7000-8000-000000000274'
const ROCK_HOLLOW_REFINERY_ID = 1035000000101
const DEEP_CORE_REFINERY_ID = 1035000000102

const ILYRA_OF_CORPORATION_A = {
  id: '00000000-0000-4000-8000-000000000274',
  authStatus: 'valid',
  mainCharacter: null,
  characters: [{ id: ILYRA_CHARACTER_ID, name: 'Ilyra Castell', isMain: true, hasValidToken: true, corporationId: CORPORATION_A, corporationName: 'Rock Hollow Mining' }],
  corporationId: CORPORATION_A,
  corporationName: 'Rock Hollow Mining',
} as unknown as User

const followedRefineries = new Set<number>()

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(NOW))
  vi.mocked(apiRequest).mockReset()
  followedRefineries.clear()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === EXTRACTIONS_PATH) {
      return response(corporation({ extractions: SYNCED_CORPORATION.extractions, followedStructureIds: [...followedRefineries] })) as never
    }
    if (endpoint === OPERATIONS_PATH && options?.method === 'POST') return { id: '0199a1f0-0000-7000-8000-0000000002a0', joinCode: null } as never
    if (endpoint === FOLLOWS_PATH && options?.method === 'POST') {
      const { structureId } = JSON.parse(String(options.body)) as { structureId: number }
      followedRefineries.add(structureId)
      return { structureId, corporationId: CORPORATION_A, createdAt: NOW } as never
    }
    if (endpoint.startsWith(`${FOLLOWS_PATH}/`) && options?.method === 'DELETE') {
      followedRefineries.delete(Number(endpoint.slice(FOLLOWS_PATH.length + 1)))
      return null as never
    }
    return null as never
  })
})

afterEach(() => {
  vi.useRealTimers()
})

async function mountTab(): Promise<VueWrapper> {
  setActivePinia(createPinia())
  useAuthStore().user = ILYRA_OF_CORPORATION_A
  const wrapper = mount(MoonChunksTab, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: { teleport: true },
    },
  })
  await flushPromises()
  return wrapper
}

function createButton(wrapper: VueWrapper, extractionId: string) {
  return wrapper.find(`[data-extraction-id="${extractionId}"] [data-action="create-operation"]`)
}

function followToggle(wrapper: VueWrapper, structureId: number) {
  const toggles = wrapper.findAll(`[data-corporation="${CORPORATION_A}"] [data-refinery-follow="${structureId}"]`)
  expect(toggles, `follow toggle of refinery ${structureId}`).toHaveLength(1)
  return toggles[0]
}

function followStates(wrapper: VueWrapper): Array<[string | undefined, string]> {
  return [ROCK_HOLLOW_REFINERY_ID, DEEP_CORE_REFINERY_ID].map((structureId): [string | undefined, string] => {
    const toggle = followToggle(wrapper, structureId)
    return [toggle.attributes('aria-pressed'), toggle.get('[data-follow-label]').text()]
  })
}

function creationForm(wrapper: VueWrapper) {
  const form = wrapper.findAll('form').find(candidate => candidate.find('input[maxlength="100"]').exists())
  if (form === undefined) throw new Error('The creation form is not shown')
  return form
}

async function submittedCreation(wrapper: VueWrapper): Promise<Record<string, unknown>> {
  await creationForm(wrapper).trigger('submit')
  await flushPromises()
  const post = vi.mocked(apiRequest).mock.calls.find(call => call[0] === OPERATIONS_PATH && call[1]?.method === 'POST')
  expect(post, 'creation request').toBeDefined()
  return JSON.parse(String(post?.[1]?.body)) as Record<string, unknown>
}

describe('MoonChunksTab, operation created from a chunk', () => {
  it('opens the creation modal prefilled with the moon, the arrival in EVE time, the corporation visibility and the system of the chunk, and offers nothing on an exploded chunk', async () => {
    const wrapper = await mountTab()

    expect(createButton(wrapper, EXPLODED_CHUNK.id).exists()).toBe(false)
    await createButton(wrapper, ROCK_HOLLOW_CHUNK.id).trigger('click')
    await flushPromises()

    const form = creationForm(wrapper)
    expect((form.get('input[maxlength="100"]').element as HTMLInputElement).value).toBe('NOL-M9 VII - Moon 3')
    expect((form.get('input[type="datetime-local"]').element as HTMLInputElement).value).toBe('2026-11-05T20:00')
    expect((form.get('input[type="radio"][value="corporation"]').element as HTMLInputElement).checked).toBe(true)
    const system = form.get('[data-chunk-system]')
    expect(system.text()).toContain('NOL-M9')
    expect(['INPUT', 'SELECT', 'TEXTAREA']).not.toContain(system.element.tagName)
  })

  it('sends the chunk with the creation request and labels a moon without a name by its identifier', async () => {
    const wrapper = await mountTab()

    await createButton(wrapper, READY_CHUNK.id).trigger('click')
    await flushPromises()
    expect((creationForm(wrapper).get('input[maxlength="100"]').element as HTMLInputElement).value).toBe('40302003')
    const body = await submittedCreation(wrapper)

    expect(body).toMatchObject({
      label: '40302003',
      plannedStartAt: '2026-11-05T17:00:00+00:00',
      visibility: 'corporation',
      characterIds: [ILYRA_CHARACTER_ID],
      chunkId: READY_CHUNK.id,
    })
  })
})

describe('MoonChunksTab, refinery follow', () => {
  it('toggles the follow of a refinery through the API, reflects followedStructureIds and points to the notification preferences', async () => {
    followedRefineries.add(ROCK_HOLLOW_REFINERY_ID)
    const wrapper = await mountTab()
    expect(followStates(wrapper)).toEqual([['true', 'Unfollow'], ['false', 'Follow']])

    await followToggle(wrapper, DEEP_CORE_REFINERY_ID).trigger('click')
    await flushPromises()
    const followRequest = vi.mocked(apiRequest).mock.calls.find(call => call[0] === FOLLOWS_PATH && call[1]?.method === 'POST')
    expect(JSON.parse(String(followRequest?.[1]?.body))).toEqual({ structureId: DEEP_CORE_REFINERY_ID })
    expect(followStates(wrapper)).toEqual([['true', 'Unfollow'], ['true', 'Unfollow']])

    await followToggle(wrapper, ROCK_HOLLOW_REFINERY_ID).trigger('click')
    await flushPromises()
    expect(vi.mocked(apiRequest).mock.calls.some(call => call[0] === `${FOLLOWS_PATH}/${ROCK_HOLLOW_REFINERY_ID}` && call[1]?.method === 'DELETE')).toBe(true)
    expect(followStates(wrapper)).toEqual([['false', 'Follow'], ['true', 'Unfollow']])
    expect(wrapper.get('[data-follow-hint]').text()).toContain('Moon Chunks')
  })
})
