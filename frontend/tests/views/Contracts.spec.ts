import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Contracts from '@/views/Contracts.vue'
import ContractsTab from '@/components/assets/ContractsTab.vue'

enableAutoUnmount(afterEach)

async function mountContracts() {
  setActivePinia(createPinia())
  const wrapper = mount(Contracts, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, RouterLink: RouterLinkStub, ContractsTab: true },
    },
  })
  await flushPromises()
  return wrapper
}

describe('Contracts page', () => {
  it('mounts the Commerce tab bar with the contracts tab active', async () => {
    const wrapper = await mountContracts()

    const currentTabs = wrapper.findAll('nav[aria-label="Commerce"] a').filter(link => link.attributes('aria-current') === 'page')
    expect(currentTabs.map(link => link.text())).toEqual(['Contracts'])
  })

  it('titles the page Contracts', async () => {
    const wrapper = await mountContracts()

    expect(wrapper.findAll('h1').map(heading => heading.text())).toEqual(['Contracts'])
  })

  it('mounts the contracts list once', async () => {
    const wrapper = await mountContracts()

    expect(wrapper.findAllComponents(ContractsTab)).toHaveLength(1)
  })
})
