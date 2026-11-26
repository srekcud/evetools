import { enableAutoUnmount, mount, RouterLinkStub } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import RevenueTodoPanel from '@/components/revenue/RevenueTodoPanel.vue'
import type { RevenueTodoItem } from '@/types/revenueGrid'

enableAutoUnmount(afterEach)

const ITEMS: RevenueTodoItem[] = [
  { text: 'Scan the loot sales', effect: 'Loot is missing from the net', actionLabel: 'Scan' },
  { text: 'Categorise the ore', effect: '142 M left out', actionLabel: 'Open', to: '/mining' },
]

function mountPanel(items: RevenueTodoItem[], locale: 'en' | 'fr' = 'en') {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(RevenueTodoPanel, { props: { items }, global: { plugins: [i18n], stubs: { RouterLink: RouterLinkStub } } })
}

describe('RevenueTodoPanel', () => {
  it('numbers each item with its text, its effect and its action', () => {
    const wrapper = mountPanel(ITEMS)

    const first = wrapper.get('[data-todo="1"]')
    expect(first.text()).toContain('1')
    expect(first.text()).toContain('Scan the loot sales')
    expect(first.text()).toContain('Loot is missing from the net')
    expect(first.get('button').text()).toBe('Scan')
    expect(wrapper.get('[data-todo="2"]').text()).toContain('Categorise the ore')
  })

  it('emits the index of the item whose action is clicked', async () => {
    const wrapper = mountPanel(ITEMS)

    await wrapper.get('[data-todo="1"] button').trigger('click')

    expect(wrapper.emitted('action')).toEqual([[0]])
  })

  it('renders a link instead of a button for an item with a target', () => {
    const wrapper = mountPanel(ITEMS)

    const second = wrapper.get('[data-todo="2"]')
    expect(second.find('button').exists()).toBe(false)
    expect(second.getComponent(RouterLinkStub).props('to')).toBe('/mining')
  })

  it('renders neither a button nor a link for an item without an action label', () => {
    const wrapper = mountPanel([{ text: 'Bring back 500 Plasma', effect: '125 m3' }])

    const first = wrapper.get('[data-todo="1"]')
    expect(first.text()).toContain('Bring back 500 Plasma')
    expect(first.find('button').exists()).toBe(false)
    expect(first.findComponent(RouterLinkStub).exists()).toBe(false)
  })

  it('renders nothing without an item', () => {
    expect(mountPanel([]).find('[data-testid="revenue-todo-panel"]').exists()).toBe(false)
  })

  it.each([
    ['en', 'To fix · skews the figures'],
    ['fr', 'À traiter · fausse les chiffres'],
  ] as const)('is titled in %s', (locale, title) => {
    expect(mountPanel(ITEMS, locale).text()).toContain(title)
  })
})
