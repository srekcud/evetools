import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContributeModal from '@/components/group-industry/ContributeModal.vue'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useGroupContributionStore } from '@/stores/group-industry/contribution'
import type { BomItem, GroupProject } from '@/stores/group-industry/types'
import { bomLine } from './companionFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn().mockResolvedValue({}),
}))

const PROJECT_ID = 'project-1224'
const PREVIOUS_ERROR = 'Failed to submit contribution'
const TRITANIUM_REMAINING = 40
const PYERITE_REMAINING = 70

const tritanium = bomLine({ id: 'bom-tritanium', typeId: 34, typeName: 'Tritanium', remainingQuantity: TRITANIUM_REMAINING })
const pyerite = bomLine({ id: 'bom-pyerite', typeId: 35, typeName: 'Pyerite', remainingQuantity: PYERITE_REMAINING })

function projectNamed(name: string | null): GroupProject {
  return {
    id: PROJECT_ID,
    name,
    status: 'in_progress',
    solo: true,
    myRole: 'owner',
    items: [{ typeName: 'Rifter', runs: 2 }, { typeName: 'Merlin', runs: 1 }],
  } as unknown as GroupProject
}

const mountedModals: VueWrapper[] = []

afterEach(() => {
  mountedModals.splice(0).forEach(modal => modal.unmount())
})

async function mountModal(name: string | null, bomItems: BomItem[], prefilledBomItem?: BomItem): Promise<VueWrapper> {
  setActivePinia(createPinia())
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = projectNamed(name)
  projectStore.bomItems = bomItems
  const wrapper = mount(ContributeModal, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })], stubs: { teleport: true } },
    props: { show: false, projectId: PROJECT_ID, prefilledBomItem },
  })
  mountedModals.push(wrapper)
  await wrapper.setProps({ show: true })
  await flushPromises()
  return wrapper
}

function quantityOf(wrapper: VueWrapper): number {
  return Number((wrapper.find('input[type="number"]').element as HTMLInputElement).value)
}

describe('ContributeModal project name', () => {
  it('shows the name of a named project', async () => {
    const wrapper = await mountModal('Fuel batch', [tritanium])

    expect(wrapper.text()).toContain('Project: Fuel batch')
  })

  it('shows the display name of an unnamed project, built from its items', async () => {
    const wrapper = await mountModal(null, [tritanium])

    expect(wrapper.text()).toContain('Project: 2x Rifter, 1x Merlin')
    expect(wrapper.text()).not.toContain('---')
  })
})

describe('ContributeModal selected item', () => {
  it('does not show the Type ID of the item', async () => {
    const wrapper = await mountModal('Fuel batch', [tritanium], tritanium)

    expect(wrapper.text()).toContain('Tritanium')
    expect(wrapper.text()).not.toContain('Type ID')
  })
})

describe('ContributeModal reopening', () => {
  it('clears the error left by the previous opening', async () => {
    const wrapper = await mountModal('Fuel batch', [tritanium])
    useGroupContributionStore().error = PREVIOUS_ERROR
    await wrapper.setProps({ show: false })
    await wrapper.setProps({ show: true })
    await flushPromises()

    expect(wrapper.text()).not.toContain(PREVIOUS_ERROR)
  })

  it('keeps showing an error raised while the modal is open', async () => {
    const wrapper = await mountModal('Fuel batch', [tritanium])
    useGroupContributionStore().error = PREVIOUS_ERROR
    await nextTick()
    await flushPromises()

    expect(wrapper.text()).toContain(PREVIOUS_ERROR)
  })
})

describe('ContributeModal changing the item', () => {
  it('prefills the quantity of the chosen item, then of the next one', async () => {
    const wrapper = await mountModal('Fuel batch', [tritanium, pyerite])

    await wrapper.find('select').setValue(tritanium.id)
    expect(quantityOf(wrapper)).toBe(TRITANIUM_REMAINING)

    await wrapper.find('button[aria-label="Clear"]').trigger('click')
    await wrapper.find('select').setValue(pyerite.id)
    expect(quantityOf(wrapper)).toBe(PYERITE_REMAINING)
  })
})
