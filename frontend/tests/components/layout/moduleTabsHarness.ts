import { flushPromises, mount } from '@vue/test-utils'
import type { Component } from 'vue'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'

const NAMED_ROUTES: Record<string, string> = {
  industry: '/industry',
  'industry-projects': '/industry/projects',
  'industry-analysis': '/industry/analysis',
  'industry-production': '/industry/production',
  'mining-operations': '/mining/operations',
  'mining-moons': '/mining/moons',
  'mining-sites': '/mining/sites',
  'mining-ore-value': '/mining/ore-value',
  'mining-my-mining': '/mining/my-mining',
  'pve-revenue': '/pve/revenue',
  'pve-escalations': '/pve/escalations',
  market: '/market',
  appraisal: '/appraisal',
  contracts: '/contracts',
  balance: '/balance',
  assets: '/assets',
  characters: '/characters',
  settings: '/settings/:section?',
}

export function moduleRoutes() {
  return Object.entries(NAMED_ROUTES).map(([name, path]) => ({ name, path, component: { template: '<div />' } }))
}

export async function mountModuleTabs(component: Component, props: Record<string, unknown>) {
  const router = createRouter({ history: createMemoryHistory(), routes: moduleRoutes() })
  await router.push('/market')
  await router.isReady()
  const wrapper = mount(component, {
    props,
    global: { plugins: [router, createI18n({ legacy: false, locale: 'en', messages: { en } })] },
  })
  await flushPromises()
  return wrapper
}

export function settingsShortcutLabel(): unknown {
  return (en.settings as Record<string, unknown>).shortcut
}
