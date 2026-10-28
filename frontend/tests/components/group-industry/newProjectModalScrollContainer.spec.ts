import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import NewProjectModal from '@/components/group-industry/NewProjectModal.vue'
import { tablesInScrollContainer } from '../tableScrollContainer'

const newProjectLabels = en.groupIndustry.modals.newProject
const BULK_ITEMS = 'Ferox 2 4 x100\nSabre 4 8 x20'

function mountNewProjectModal() {
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(NewProjectModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: true },
  })
}

async function clickButton(wrapper: VueWrapper, label: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === label)
  expect(button, `button ${label}`).toBeDefined()
  await button!.trigger('click')
}

async function mountWithItemsAndLineRentalOverride() {
  const wrapper = mountNewProjectModal()
  await clickButton(wrapper, newProjectLabels.bulkPaste)
  await wrapper.get('textarea').setValue(BULK_ITEMS)
  await clickButton(wrapper, newProjectLabels.parseAndAdd)
  await clickButton(wrapper, newProjectLabels.overrideForProject)
  return wrapper
}

describe('NewProjectModal on a narrow screen', () => {
  it('puts the items table and the line rental override table in horizontally scrollable containers', async () => {
    const wrapper = await mountWithItemsAndLineRentalOverride()

    expect(tablesInScrollContainer(wrapper)).toEqual([true, true])
  })
})
