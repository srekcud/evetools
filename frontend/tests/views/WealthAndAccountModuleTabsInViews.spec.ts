import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { Component } from 'vue'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Assets from '@/views/Assets.vue'
import Balance from '@/views/Balance.vue'
import Characters from '@/views/Characters.vue'
import { moduleRoutes } from '../components/layout/moduleTabsHarness'

const stubbedChildren = {
  MainLayout: { template: '<div><slot /></div>' },
  ErrorBanner: true,
  LoadingSpinner: true,
}

async function mountViewAt(view: Component, path: string): Promise<VueWrapper> {
  const pinia = createPinia()
  setActivePinia(pinia)
  vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})))
  const router = createRouter({ history: createMemoryHistory(), routes: moduleRoutes() })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(view, {
    global: { plugins: [pinia, router, createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: stubbedChildren },
  })
  await flushPromises()
  return wrapper
}

function currentTabLabels(wrapper: VueWrapper, navigationLabel: string): string[] {
  return wrapper
    .findAll(`nav[aria-label="${navigationLabel}"] a`)
    .filter(link => link.attributes('aria-current') === 'page')
    .map(link => link.text())
}

enableAutoUnmount(afterEach)

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('Wealth and Account module tabs in the module pages', () => {
  it.each([
    ['Balance', Balance, '/balance', 'Wealth', 'Balance'],
    ['Inventory', Assets, '/assets', 'Wealth', 'Inventory'],
    ['Characters', Characters, '/characters', 'Account', 'Characters'],
  ])('shows the %s page under its module tab bar with its own tab current', async (_page, view, path, navigationLabel, currentLabel) => {
    const wrapper = await mountViewAt(view, path)

    expect(currentTabLabels(wrapper, navigationLabel)).toEqual([currentLabel])
  })
})
