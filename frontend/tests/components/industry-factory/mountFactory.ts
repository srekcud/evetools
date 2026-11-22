import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import type { Component } from 'vue'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'
import { stubBackend, type BackendAnswer, type BackendCall } from '../../backendStub'

export interface MountedFactory {
  wrapper: VueWrapper
  router: Router
  pinia: Pinia
  calls: BackendCall[]
}

export async function mountFactory(
  component: Component,
  options: { answers?: Record<string, BackendAnswer>; seed?: (pinia: Pinia) => void; stubs?: Record<string, unknown> } = {},
): Promise<MountedFactory> {
  const calls = stubBackend(options.answers ?? {})
  const pinia = createPinia()
  setActivePinia(pinia)
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = { id: 'user-1', authStatus: 'valid', mainCharacter: null, characters: [], corporationId: 98_000_001, corporationName: 'Test Corp' } as User
  options.seed?.(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/industry/projects', name: 'industry-projects', component: { template: '<div />' } },
      { path: '/industry/projects/:id', name: 'industry-project', component: { template: '<div />' } },
      { path: '/industry/factory', name: 'industry-factory', component: { template: '<div />' } },
    ],
  })
  await router.push('/industry/factory')
  await router.isReady()
  const wrapper = mount(component, {
    global: {
      plugins: [pinia, router, createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, ...options.stubs },
    },
  })
  await flushPromises()
  return { wrapper, router, pinia, calls }
}

export function isUnknown(element: Pick<DOMWrapper<Element>, 'attributes' | 'find'>): boolean {
  return element.attributes('data-unknown') === 'true' || element.find('[data-unknown="true"]').exists()
}
