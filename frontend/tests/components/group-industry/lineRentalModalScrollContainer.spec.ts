import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import LineRentalModal from '@/components/group-industry/LineRentalModal.vue'
import { tablesInScrollContainer } from '../tableScrollContainer'

function mountLineRentalModal() {
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(LineRentalModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: true },
  })
}

describe('LineRentalModal on a narrow screen', () => {
  it('puts the line rental rates table in a horizontally scrollable container', () => {
    const wrapper = mountLineRentalModal()

    expect(tablesInScrollContainer(wrapper)).toEqual([true])
  })
})
