import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Characters from '@/views/Characters.vue'
import CharacterCard from '@/components/characters/CharacterCard.vue'
import MainCharacterCard from '@/components/characters/MainCharacterCard.vue'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRouter: vi.fn(() => ({ push: vi.fn() })) as never,
}))

const MAIN_CHARACTER_ID = '00000000-0000-4000-8000-000000231001'
const ALT_CHARACTER_ID = '00000000-0000-4000-8000-000000231002'
const MAIN_BALANCE = 1500000.5

function character(id: string, eveCharacterId: number, name: string, isMain: boolean) {
  return {
    id,
    eveCharacterId,
    name,
    corporationId: 98231001,
    corporationName: 'Castell Freight Union',
    allianceId: null,
    allianceName: null,
    isMain,
    hasValidToken: true,
    hasMissingScopes: false,
    lastSyncAt: null,
  }
}

const responsesByUrl: Record<string, unknown> = {
  '/api/me/characters': {
    member: [
      character(MAIN_CHARACTER_ID, 2112231001, 'Ilyra Castell', true),
      character(ALT_CHARACTER_ID, 2112231002, 'Doran Vex', false),
    ],
  },
  '/api/me/wallets': {
    id: 'current',
    totalBalance: MAIN_BALANCE,
    incomplete: true,
    wallets: [
      { characterId: MAIN_CHARACTER_ID, characterName: 'Ilyra Castell', isMain: true, balance: MAIN_BALANCE },
      { characterId: ALT_CHARACTER_ID, characterName: 'Doran Vex', isMain: false, balance: null },
    ],
  },
  '/api/me/skillqueues': { skillQueues: [] },
}

async function mountCharacters() {
  const pinia = createPinia()
  setActivePinia(pinia)
  vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
    const url = String(input)
    const body = responsesByUrl[url]
    return body === undefined
      ? new Response('{}', { status: 404 })
      : new Response(JSON.stringify(body), { status: 200 })
  }))
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(Characters, {
    global: {
      plugins: [pinia, i18n],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, ErrorBanner: true, ConfirmModal: true },
    },
  })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

describe('Characters wallet with an unknown balance', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('labels the character whose balance is null as unknown instead of an ISK amount', async () => {
    const wrapper = await mountCharacters()

    const altCardText = wrapper.findComponent(CharacterCard).text()

    expect(altCardText).toContain('Doran Vex')
    expect(altCardText).toContain(en.characters.walletUnknown)
    expect(altCardText).not.toContain('--- ISK')
  })

  it('keeps the known balance of the main character as an ISK amount', async () => {
    const wrapper = await mountCharacters()

    const mainCardText = wrapper.findComponent(MainCharacterCard).text()

    expect(mainCardText).toContain('Ilyra Castell')
    expect(mainCardText).toContain('1.5 M ISK')
    expect(mainCardText).not.toContain(en.characters.walletUnknown)
  })
})
