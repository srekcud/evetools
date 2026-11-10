import { flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import type { Component } from 'vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PveTab from '@/components/ledger/PveTab.vue'

export async function mountPveModulePage(page: Component, locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  const wrapper = mount(page, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        LoadingSpinner: true,
        PveTab: true,
        EscalationsTab: true,
        RouterLink: RouterLinkStub,
      },
    },
  })
  await flushPromises()
  return wrapper
}

export function currentTabTargets(wrapper: VueWrapper): unknown[] {
  return wrapper.findAllComponents(RouterLinkStub)
    .filter(link => link.attributes('aria-current') === 'page')
    .map(link => link.props('to'))
}

export function periodOfPveTab(wrapper: VueWrapper): number | null {
  const pveTab = wrapper.findComponent(PveTab)
  return pveTab.exists() ? pveTab.props('selectedDays') : null
}

export function buttonsLabelled(wrapper: VueWrapper, label: string) {
  return wrapper.findAll('button').filter(button => button.text() === label)
}
