import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import AccountMenu from '@/components/layout/AccountMenu.vue'

enableAutoUnmount(afterEach)

async function mountOpenMenu() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['/dashboard', '/settings', '/characters'].map(path => ({ path, component: { template: '<div />' } })),
  })
  await router.push('/dashboard')
  await router.isReady()
  const wrapper = mount(AccountMenu, {
    props: { character: null, isLoggingOut: false },
    attachTo: document.body,
    global: { plugins: [router, createI18n({ legacy: false, locale: 'en', messages: { en } })] },
  })
  await wrapper.get('button[aria-haspopup="menu"]').trigger('click')
  await flushPromises()
  return { wrapper, router }
}

describe('AccountMenu', () => {
  it('turns the settings item into a link to the settings page and emits no openSettings event', async () => {
    const { wrapper, router } = await mountOpenMenu()

    const settingsItem = wrapper.findAll('[role="menuitem"]').find(item => item.text() === en.settings.title)
    await settingsItem?.trigger('click')
    await flushPromises()

    expect(settingsItem?.element.tagName).toBe('A')
    expect(settingsItem?.attributes('href')).toBe('/settings')
    expect(router.currentRoute.value.path).toBe('/settings')
    expect(wrapper.emitted('openSettings')).toBeUndefined()
    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
  })
})
