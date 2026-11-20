import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import GroupCompanion from '@/components/group-industry/GroupCompanion.vue'
import GroupCompanionHost from '@/components/group-industry/GroupCompanionHost.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { useGroupCompanionStore } from '@/stores/group-industry/companion'
import { COMPANION_PROJECT_ID, companionProject } from './companionFixtures'

const router = vi.hoisted(() => ({ push: vi.fn(), replace: vi.fn() }))

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRouter: (() => router) as never,
}))

enableAutoUnmount(afterEach)

function pictureInPictureWindowStub(): Window {
  return {
    document: document.implementation.createHTMLDocument('companion'),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    resizeTo: vi.fn(),
    close: vi.fn(),
    focus: vi.fn(),
  } as unknown as Window
}

async function mountOpenCompanion() {
  Object.defineProperty(window, 'documentPictureInPicture', {
    value: { requestWindow: vi.fn(async () => pictureInPictureWindowStub()) },
    configurable: true,
  })
  setActivePinia(createPinia())
  const companion = useGroupCompanionStore()
  companion.projectId = COMPANION_PROJECT_ID
  companion.project = companionProject()
  const wrapper = mount(GroupCompanionHost, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { GroupCompanion: true },
    },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  router.push.mockReset()
})

afterEach(() => {
  Reflect.deleteProperty(window, 'documentPictureInPicture')
})

describe('GroupCompanionHost navigation to the project', () => {
  it('opens the production tab of the project on the line to deliver', async () => {
    const wrapper = await mountOpenCompanion()

    wrapper.getComponent(GroupCompanion).vm.$emit('deliver', 'line-ishtar-hull')
    await flushPromises()

    expect(router.push.mock.calls).toEqual([[{
      name: 'industry-project',
      params: { id: COMPANION_PROJECT_ID },
      query: { tab: 'chain', contribute: 'line-ishtar-hull' },
    }]])
  })

  it('opens the production tab of the project from the open project action', async () => {
    const wrapper = await mountOpenCompanion()

    wrapper.getComponent(GroupCompanion).vm.$emit('open-bom')
    await flushPromises()

    expect(router.push.mock.calls).toEqual([[{
      name: 'industry-project',
      params: { id: COMPANION_PROJECT_ID },
      query: { tab: 'chain' },
    }]])
  })
})
