import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Assets from '@/views/Assets.vue'
import AssetsFilterBar from '@/components/assets/AssetsFilterBar.vue'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import type { User } from '@/types'
import { assetBrowseFetch } from '../assetBrowseApi'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ query: {}, params: {} })) as never,
  useRouter: (() => ({ replace: vi.fn() })) as never,
}))

const CHARACTER_A_ID = '00000000-0000-4000-8000-0000000000c1'
const CHARACTER_B_ID = '00000000-0000-4000-8000-0000000000c2'
const CHANGES_A = `/api/me/characters/${CHARACTER_A_ID}/assets/changes`
const CHANGES_B = `/api/me/characters/${CHARACTER_B_ID}/assets/changes`
const CORPORATION_CHANGES = '/api/me/corporation/assets/changes'
const PANEL = '[data-testid="asset-changes-panel"]'

enableAutoUnmount(afterEach)

beforeEach(() => {
  localStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

function changesPayload(typeName: string) {
  return {
    syncedAt: '2026-11-13T09:00:00+00:00',
    previousSyncedAt: '2026-11-13T08:00:00+00:00',
    total: 1,
    changes: [{ typeId: 24698, typeName, locationId: 60003760, locationName: 'Jita IV', divisionName: null, quantityDelta: 3 }],
  }
}

function identity(id: string, name: string, isMain: boolean) {
  return {
    id,
    eveCharacterId: isMain ? 2112000001 : 2112000002,
    name,
    corporationId: 98000001,
    corporationName: 'Test Corp',
    allianceId: null,
    allianceName: null,
    isMain,
    hasValidToken: true,
    lastSyncAt: null,
  }
}

async function mountAssets(answers: Record<string, () => Response>) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = {
    id: '00000000-0000-4000-8000-0000000000u1',
    authStatus: 'valid',
    mainCharacter: null,
    characters: [identity(CHARACTER_A_ID, 'Pilot A', true), identity(CHARACTER_B_ID, 'Pilot B', false)],
    corporationId: 98000001,
    corporationName: 'Test Corp',
  } as User
  const requested: string[] = []
  const fetchMock = vi.fn(assetBrowseFetch([], endpoint => {
    requested.push(endpoint)
    if (endpoint.endsWith('/visibility')) {
      return new Response(JSON.stringify({ isDirector: true, accessibleDivisions: [], allDivisions: {}, updatedAt: null }), { status: 200 })
    }
    const answer = answers[endpoint]
    return answer ? answer() : null
  }))
  vi.stubGlobal('fetch', fetchMock)
  const wrapper = mount(Assets, {
    global: {
      plugins: [pinia, createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        OpenInGameButton: true,
        AssetDistributionChart: true,
        CorpVisibilityPanel: true,
      },
    },
  })
  await flushPromises()
  return { wrapper, requested, syncStore: useSyncStore() }
}

function ok(payload: unknown) {
  return () => new Response(JSON.stringify(payload), { status: 200 })
}

describe('Assets view: changes since the previous sync', () => {
  it('reads the diff of the selected character and shows the panel', async () => {
    const { wrapper, requested } = await mountAssets({ [CHANGES_A]: ok(changesPayload('Drake')) })

    expect(requested).toContain(CHANGES_A)
    expect(wrapper.find(PANEL).exists()).toBe(true)
    expect(wrapper.find(PANEL).text()).toContain(en.assets.changes.title)
  })

  it('reads the diff of the other character after the switch', async () => {
    const { wrapper, requested } = await mountAssets({
      [CHANGES_A]: ok(changesPayload('Drake')),
      [CHANGES_B]: ok(changesPayload('Raven')),
    })

    wrapper.findComponent(AssetsFilterBar).vm.$emit('update:selected-character-id', CHARACTER_B_ID)
    await flushPromises()

    expect(requested).toContain(CHANGES_B)
    await wrapper.find('[data-testid="asset-changes-toggle"]').trigger('click')
    expect(wrapper.find(PANEL).text()).toContain('Raven')
  })

  it('reads the corporation diff in the corporation view', async () => {
    const { wrapper, requested } = await mountAssets({ [CORPORATION_CHANGES]: ok(changesPayload('Drake')) })

    wrapper.findComponent(AssetsFilterBar).vm.$emit('update:view-mode', 'corporation')
    await flushPromises()

    expect(requested).toContain(CORPORATION_CHANGES)
  })

  it('reads the diff again when the character sync completes', async () => {
    const { requested, syncStore } = await mountAssets({ [CHANGES_A]: ok(changesPayload('Drake')) })
    const before = requested.filter(endpoint => endpoint === CHANGES_A).length

    syncStore.syncStatus['character-assets'] = { syncType: 'character-assets', status: 'completed', progress: null, message: null, data: null, timestamp: '2026-11-13T10:05:00+00:00' }
    await flushPromises()

    expect(requested.filter(endpoint => endpoint === CHANGES_A).length).toBe(before + 1)
  })

  it('shows the panel error and keeps the inventory when the diff cannot be read', async () => {
    const { wrapper } = await mountAssets({ [CHANGES_A]: () => new Response('{}', { status: 500 }) })

    expect(wrapper.find('[data-testid="asset-changes-error"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain(en.assets.loadFailed)
  })
})
