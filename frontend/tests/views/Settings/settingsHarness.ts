import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import Settings from '@/views/Settings.vue'
import { moduleRoutes } from '../../components/layout/moduleTabsHarness'

export { callsTo, FailedAnswer, stubBackend, type BackendAnswer, type BackendCall } from '../../backendStub'

export const SETTINGS_SECTIONS = ['account', 'display', 'market', 'industry', 'mining', 'pve', 'notifications']

interface SettingsMountOptions {
  locale?: 'en' | 'fr'
  stubs?: Record<string, unknown>
}

export async function mountSettingsAt(path: string, { locale = 'en', stubs = {} }: SettingsMountOptions = {}) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({ history: createMemoryHistory(), routes: moduleRoutes() })
  await router.push(path)
  await router.isReady()
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(Settings, {
    attachTo: document.body,
    global: {
      plugins: [pinia, router, i18n],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, ...stubs },
    },
  })
  await flushPromises()
  return { wrapper, router, i18n }
}
