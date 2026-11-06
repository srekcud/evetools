import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const TE_LABEL = en.industry.createProject.te
const EVEN_TE_ATTRIBUTES = { step: '2', min: '0', max: '20' }

function i18n() {
  return createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
}

function teAttributesOf(wrapper: VueWrapper, selector: string) {
  const input = wrapper.find(selector)
  if (!input.exists()) return null
  return { step: input.attributes('step'), min: input.attributes('min'), max: input.attributes('max') }
}

function labelledTeInputSelector(wrapper: VueWrapper): string {
  const label = wrapper.findAll('label').find(candidate => candidate.text().trim() === TE_LABEL)
  if (!label) throw new Error('No TE label')
  return `[id="${label.attributes('for')}"]`
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue(null)
})

describe('TE inputs step by two', () => {
  it('steps the TE of the profit margin analysis by two from 0 to 20', () => {
    const wrapper = mount(ProfitMarginTab, { global: { plugins: [i18n()], stubs: { ProductSearch: true } } })

    expect(teAttributesOf(wrapper, labelledTeInputSelector(wrapper))).toEqual(EVEN_TE_ATTRIBUTES)
  })
})
