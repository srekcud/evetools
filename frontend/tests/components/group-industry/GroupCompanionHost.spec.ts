import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import GroupCompanionHost from '@/components/group-industry/GroupCompanionHost.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { useGroupCompanionStore } from '@/stores/group-industry/companion'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
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

afterEach(() => {
  Reflect.deleteProperty(window, 'documentPictureInPicture')
})

describe('GroupCompanionHost', () => {
  it('keeps a single window with a single content container when the project changes while the window is still opening', async () => {
    const pictureInPictureWindow = pictureInPictureWindowStub()
    let grant: (granted: Window) => void = () => {}
    const requestWindow = vi.fn(() => new Promise<Window>((resolve) => { grant = resolve }))
    Object.defineProperty(window, 'documentPictureInPicture', { value: { requestWindow }, configurable: true })
    setActivePinia(createPinia())
    const companion = useGroupCompanionStore()
    companion.projectId = 'project-1'

    mount(GroupCompanionHost, {
      global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
    })
    companion.projectId = 'project-2'
    await flushPromises()
    grant(pictureInPictureWindow)
    await flushPromises()

    expect({
      requests: requestWindow.mock.calls.length,
      containers: pictureInPictureWindow.document.body.children.length,
      stillOpen: companion.projectId,
      closed: vi.mocked(pictureInPictureWindow.close).mock.calls.length,
    }).toEqual({ requests: 1, containers: 1, stillOpen: 'project-2', closed: 0 })
  })
})
