import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { createI18n } from 'vue-i18n'
import GroupCompanionHost from '@/components/group-industry/GroupCompanionHost.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { useGroupCompanionStore } from '@/stores/group-industry/companion'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

enableAutoUnmount(afterEach)

const PROJECT_ID = 'project-nyx-batch'

const projectEvents = ref<Record<string, GroupProjectEvent>>({})

function pictureInPictureWindowStub(visibilityState: 'visible' | 'hidden'): Window {
  const companionDocument = document.implementation.createHTMLDocument('companion')
  Object.defineProperty(companionDocument, 'visibilityState', { get: () => visibilityState, configurable: true })
  return {
    document: companionDocument,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    resizeTo: vi.fn(),
    close: vi.fn(),
    focus: vi.fn(),
  } as unknown as Window
}

async function openCompanion(mainTab: 'visible' | 'hidden', companionWindow: 'visible' | 'hidden') {
  vi.spyOn(document, 'visibilityState', 'get').mockReturnValue(mainTab)
  const requestWindow = vi.fn().mockResolvedValue(pictureInPictureWindowStub(companionWindow))
  Object.defineProperty(window, 'documentPictureInPicture', { value: { requestWindow }, configurable: true })
  setActivePinia(createPinia())
  projectEvents.value = {}
  vi.spyOn(useSyncStore(), 'getGroupProjectEvent').mockImplementation(projectId => projectEvents.value[projectId] ?? null)
  const companion = useGroupCompanionStore()
  const handleEvents = vi.spyOn(companion, 'handleEvents').mockResolvedValue(undefined)
  companion.projectId = PROJECT_ID
  mount(GroupCompanionHost, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
  await flushPromises()
  return handleEvents
}

function publish(action: string): void {
  projectEvents.value = { ...projectEvents.value, [PROJECT_ID]: { action, data: null, timestamp: '2026-11-21T10:00:00+00:00' } }
}

afterEach(() => {
  Reflect.deleteProperty(window, 'documentPictureInPicture')
  vi.restoreAllMocks()
})

describe('GroupCompanionHost hidden main tab', () => {
  it('refreshes the companion at once on a project event while its own window is visible and the main tab is hidden', async () => {
    const handleEvents = await openCompanion('hidden', 'visible')

    publish('line_reserved')
    await flushPromises()

    expect(handleEvents.mock.calls).toEqual([[['line_reserved']]])
  })

  it('does not refresh the companion on a project event while its own window is hidden', async () => {
    const handleEvents = await openCompanion('visible', 'hidden')

    publish('line_reserved')
    await flushPromises()

    expect(handleEvents).not.toHaveBeenCalled()
  })
})
