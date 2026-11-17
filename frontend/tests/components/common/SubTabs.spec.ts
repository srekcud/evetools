import { enableAutoUnmount, mount } from '@vue/test-utils'
import SubTabs from '@/components/common/SubTabs.vue'

enableAutoUnmount(afterEach)

const TABS = [
  { id: 'ledger', label: 'Ledger' },
  { id: 'season', label: 'Season', count: 3 },
  { id: 'empty', label: 'Empty', count: 0 },
]

describe('SubTabs', () => {
  it('renders one tab per entry in order', () => {
    const wrapper = mount(SubTabs, { props: { tabs: TABS, modelValue: 'ledger' } })

    expect(wrapper.findAll('[role="tab"]').map(tab => tab.text())).toEqual(['Ledger', 'Season 3', 'Empty 0'])
  })

  it('marks only the active tab as selected', () => {
    const wrapper = mount(SubTabs, { props: { tabs: TABS, modelValue: 'season' } })

    expect(wrapper.findAll('[role="tab"]').map(tab => tab.attributes('aria-selected'))).toEqual(['false', 'true', 'false'])
  })

  it('emits the clicked tab id', async () => {
    const wrapper = mount(SubTabs, { props: { tabs: TABS, modelValue: 'ledger' } })

    await wrapper.findAll('[role="tab"]')[1]?.trigger('click')

    expect(wrapper.emitted('update:modelValue')).toEqual([['season']])
  })

  it('shows a count badge of zero but none when the count is absent', () => {
    const wrapper = mount(SubTabs, { props: { tabs: TABS, modelValue: 'ledger' } })

    const badges = wrapper.findAll('[role="tab"]').map(tab => tab.find('span'))
    expect(badges.map(badge => badge.exists())).toEqual([false, true, true])
    expect(badges[2]?.text()).toBe('0')
  })
})
