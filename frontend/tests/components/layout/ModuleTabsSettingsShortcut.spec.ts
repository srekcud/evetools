import { enableAutoUnmount } from '@vue/test-utils'
import type { Component } from 'vue'
import PatrimoineModuleTabs from '@/components/patrimoine/PatrimoineModuleTabs.vue'
import CommerceModuleTabs from '@/components/commerce/CommerceModuleTabs.vue'
import IndustryModuleTabs from '@/components/industry-module/IndustryModuleTabs.vue'
import MiningModuleTabs from '@/components/mining-module/MiningModuleTabs.vue'
import PveModuleTabs from '@/components/pve-module/PveModuleTabs.vue'
import { mountModuleTabs, settingsShortcutLabel } from './moduleTabsHarness'

const BARS: Array<[string, Component, string, string]> = [
  ['industry', IndustryModuleTabs, 'workshop', '/settings/industry'],
  ['mining', MiningModuleTabs, 'operations', '/settings/mining'],
  ['pve', PveModuleTabs, 'revenue', '/settings/pve'],
  ['commerce', CommerceModuleTabs, 'market', '/settings/market'],
]

enableAutoUnmount(afterEach)

describe('module tab bars settings shortcut', () => {
  it.each(BARS)('links the %s bar to its settings section', async (_module, bar, active, expectedHref) => {
    const wrapper = await mountModuleTabs(bar, { active })

    const shortcuts = wrapper.findAll('a').filter(link => link.attributes('href') === expectedHref)

    expect(typeof settingsShortcutLabel()).toBe('string')
    expect(shortcuts).toHaveLength(1)
    expect(shortcuts[0].attributes('aria-label')).toBe(settingsShortcutLabel())
    expect(wrapper.findAll('a[href^="/settings"]')).toHaveLength(1)
  })
})

describe('wealth bar settings shortcut', () => {
  it('shows no settings shortcut because no settings section holds wealth settings', async () => {
    const wrapper = await mountModuleTabs(PatrimoineModuleTabs, { active: 'balance' })

    expect(wrapper.findAll('a[href^="/settings"]')).toHaveLength(0)
    expect(wrapper.findAll('a')).toHaveLength(2)
  })
})
