import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ProjectTabBar from '@/components/group-industry/ProjectTabBar.vue'

const mounted: VueWrapper[] = []

function mountTabBar(pendingContributionCount: number | null): VueWrapper {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(ProjectTabBar, {
    props: {
      modelValue: 'bom',
      materialsCount: '3 items',
      pendingContributionCount,
      memberCount: 2,
    },
    global: { plugins: [i18n] },
  })
  mounted.push(wrapper)
  return wrapper
}

function contributionsTab(wrapper: VueWrapper) {
  return wrapper.get('[data-testid="project-tab-contributions"]')
}

afterEach(() => {
  mounted.splice(0).forEach(wrapper => wrapper.unmount())
})

describe('ProjectTabBar contributions badge', () => {
  it('shows the contributions waiting for review in amber', () => {
    const badge = contributionsTab(mountTabBar(3)).get('span')

    expect(badge.text()).toBe('3')
    expect(badge.classes()).toContain('text-amber-400')
  })

  it('shows no badge when nothing waits for review', () => {
    expect(contributionsTab(mountTabBar(0)).find('span').exists()).toBe(false)
  })

  it('shows no badge when the count is not exposed to the viewer', () => {
    expect(contributionsTab(mountTabBar(null)).find('span').exists()).toBe(false)
  })

  it('keeps the badges of the other tabs', () => {
    const wrapper = mountTabBar(1)

    expect(wrapper.get('[data-testid="project-tab-bom"]').get('span').text()).toBe('3 items')
    expect(wrapper.get('[data-testid="project-tab-members"]').get('span').text()).toBe('2')
  })
})
