import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PinnedContainersPanel from '@/components/assets/PinnedContainersPanel.vue'
import type { PinnedContainer } from '@/types'

function entry(overrides: Partial<PinnedContainer>): PinnedContainer {
  return {
    id: '1',
    itemId: 1,
    name: 'Sylvain - Reactions',
    locationName: 'Jita IV - Moon 4',
    solarSystemName: 'Jita',
    divisionName: 'Reactions',
    itemCount: 1250,
    found: true,
    projectId: null,
    projectName: null,
    ...overrides,
  }
}

function mountPanel(entries: PinnedContainer[]) {
  return mount(PinnedContainersPanel, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
    props: { entries },
  })
}

describe('PinnedContainersPanel', () => {
  it('renders nothing without any entry', () => {
    expect(mountPanel([]).find('[data-testid="pinned-panel"]').exists()).toBe(false)
  })

  it('shows location, division and item count of each manual pin under the pinned containers title', () => {
    const wrapper = mountPanel([entry({})])

    const text = wrapper.get('[data-testid="pinned-panel"]').text()
    expect(text).toContain('My pinned containers')
    expect(text).toContain('Sylvain - Reactions')
    expect(text).toContain('Jita IV - Moon 4')
    expect(text).toContain('Reactions')
    expect(text).toContain('1,250 items')
  })

  it('groups project containers under their project with the number of containers watched', () => {
    const wrapper = mountPanel([
      entry({ id: '1', itemId: 1, name: 'Capital box A', projectId: 'p1', projectName: 'Capital rush' }),
      entry({ id: '2', itemId: 2, name: 'Capital box B', projectId: 'p1', projectName: 'Capital rush' }),
      entry({ id: '3', itemId: 3, name: 'Joint box', projectId: 'p2', projectName: 'Joint build' }),
    ])

    const groups = wrapper.findAll('[data-testid="pinned-project-group"]')
    expect(groups).toHaveLength(2)
    expect(groups[0].text()).toContain('Capital rush · 2 containers watched')
    expect(groups[0].text()).toContain('Capital box B')
    expect(groups[1].text()).toContain('Joint build · 1 container watched')
  })

  it('asks to open the container when an entry is clicked', async () => {
    const wrapper = mountPanel([entry({ itemId: 42 })])

    await wrapper.get('[data-testid="pinned-entry"]').trigger('click')

    expect(wrapper.emitted('open')).toEqual([[42]])
  })

  it('greys an entry missing from the last sync and does not open it', async () => {
    const wrapper = mountPanel([entry({ itemId: 42, found: false, locationName: null, divisionName: null, itemCount: null })])
    const row = wrapper.get('[data-testid="pinned-entry"]')

    await row.trigger('click')

    expect(row.text()).toContain('Not found in the last sync')
    expect(row.classes()).toContain('opacity-50')
    expect(wrapper.emitted('open')).toBeUndefined()
  })
})
