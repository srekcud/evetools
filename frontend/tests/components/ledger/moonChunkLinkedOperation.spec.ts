import { enableAutoUnmount, flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MoonChunksTab from '@/components/ledger/MoonChunksTab.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'
import {
  CORPORATION_A,
  DEEP_CORE_CHUNK,
  EXPLODED_OPERATION,
  LINKED_EXPLODED_CHUNK,
  LINKED_ROCK_HOLLOW_CHUNK,
  NOW,
  ROCK_HOLLOW_OPERATION,
  corporation,
  response,
} from './moonChunksHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const EXTRACTIONS_PATH = '/mining/corporation-extractions'
const CREATE_BUTTON = '[data-action="create-operation"]'
const LINKED_OPERATION = '[data-linked-operation]'
const LINKED_OPERATION_MORE = '[data-linked-operation-more]'
const PLAN_THE_OPERATION = 'Planifier l\'opération'
const LINKED_OPERATION_LABEL = 'Opération liée →'

const ILYRA_OF_CORPORATION_A = {
  id: '00000000-0000-4000-8000-000000000531',
  authStatus: 'valid',
  mainCharacter: null,
  characters: [{ id: '0199a1f0-0000-7000-8000-000000000531', name: 'Ilyra Castell', isMain: true, hasValidToken: true, corporationId: CORPORATION_A, corporationName: 'Rock Hollow Mining' }],
  corporationId: CORPORATION_A,
  corporationName: 'Rock Hollow Mining',
} as unknown as User

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(NOW))
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === EXTRACTIONS_PATH) {
      return response(corporation({ extractions: [LINKED_EXPLODED_CHUNK, LINKED_ROCK_HOLLOW_CHUNK, DEEP_CORE_CHUNK] })) as never
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
      plugins: [createI18n({ legacy: false, locale: 'fr', messages: { en, fr } })],
      stubs: { teleport: true, RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return wrapper
}

function listRow(wrapper: VueWrapper, extractionId: string) {
  return wrapper.get(`[data-extraction-id="${extractionId}"]`)
}

function weekCard(wrapper: VueWrapper, extractionId: string) {
  return wrapper.get(`[data-chunk="${extractionId}"]`)
}

function operationLinkOf(container: ReturnType<typeof listRow>) {
  return container.getComponent(RouterLinkStub)
}

describe('MoonChunksTab, operation linked to a chunk', () => {
  it('replaces the planning button by a link to the latest operation with the number of other operations, in the list and in the week', async () => {
    const wrapper = await mountTab()
    const row = listRow(wrapper, LINKED_ROCK_HOLLOW_CHUNK.id)

    expect({
      link: row.get(LINKED_OPERATION).text(),
      target: operationLinkOf(row).props('to'),
      more: row.get(LINKED_OPERATION_MORE).text(),
      button: row.find(CREATE_BUTTON).exists(),
      unlinkedChunkButton: listRow(wrapper, DEEP_CORE_CHUNK.id).find(CREATE_BUTTON).exists(),
      unlinkedChunkLink: listRow(wrapper, DEEP_CORE_CHUNK.id).find(LINKED_OPERATION).exists(),
    }).toEqual({
      link: LINKED_OPERATION_LABEL,
      target: { name: 'mining-operation-detail', params: { id: ROCK_HOLLOW_OPERATION.id } },
      more: '+1',
      button: false,
      unlinkedChunkButton: true,
      unlinkedChunkLink: false,
    })

    await wrapper.get('[data-view="week"]').trigger('click')
    const card = weekCard(wrapper, LINKED_ROCK_HOLLOW_CHUNK.id)

    expect({
      link: card.get(LINKED_OPERATION).text(),
      target: operationLinkOf(card).props('to'),
      more: card.get(LINKED_OPERATION_MORE).text(),
      button: card.find(CREATE_BUTTON).exists(),
      unlinkedChunkButton: weekCard(wrapper, DEEP_CORE_CHUNK.id).find(CREATE_BUTTON).exists(),
    }).toEqual({
      link: LINKED_OPERATION_LABEL,
      target: { name: 'mining-operation-detail', params: { id: ROCK_HOLLOW_OPERATION.id } },
      more: '+1',
      button: false,
      unlinkedChunkButton: true,
    })
  })

  it('labels the button Planifier l\'opération on a chunk without operation of the user and opens the creation modal prefilled with the moon', async () => {
    const wrapper = await mountTab()
    const button = listRow(wrapper, DEEP_CORE_CHUNK.id).get(CREATE_BUTTON)

    expect(button.text()).toContain(PLAN_THE_OPERATION)
    await button.trigger('click')
    await flushPromises()

    const form = wrapper.findAll('form').find(candidate => candidate.find('input[maxlength="100"]').exists())
    expect(form, 'creation form').toBeDefined()
    expect((form?.get('input[maxlength="100"]').element as HTMLInputElement).value).toBe('Ihakana II - Moon 1')
    expect((form?.get('input[type="datetime-local"]').element as HTMLInputElement).value).toBe('2026-11-08T06:15')
  })

  it('keeps the link to the operation of an exploded chunk without offering to plan one, and shows no other operation count for a single operation', async () => {
    const wrapper = await mountTab()
    const row = listRow(wrapper, LINKED_EXPLODED_CHUNK.id)

    expect({
      link: row.get(LINKED_OPERATION).text(),
      target: operationLinkOf(row).props('to'),
      more: row.find(LINKED_OPERATION_MORE).exists(),
      button: row.find(CREATE_BUTTON).exists(),
    }).toEqual({
      link: LINKED_OPERATION_LABEL,
      target: { name: 'mining-operation-detail', params: { id: EXPLODED_OPERATION.id } },
      more: false,
      button: false,
    })
  })
})
