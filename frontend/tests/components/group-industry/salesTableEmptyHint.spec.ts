import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-226'
const OLD_EMPTY_HINT = 'Record sales once items have been sold on the market'

async function emptySalesTableText(locale: 'en' | 'fr'): Promise<string> {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockResolvedValue([])
  const wrapper = mount(SalesTable, {
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })], stubs: { LoadingSpinner: true } },
    props: { projectId: PROJECT_ID, isAdmin: true },
  })
  await flushPromises()
  return wrapper.text()
}

describe('SalesTable without any sale', () => {
  it('points to the wallet transactions to link', async () => {
    const text = await emptySalesTableText('en')

    expect(text).not.toContain(OLD_EMPTY_HINT)
    expect(text).toContain(en.groupIndustry.saleSuggestions.title)
    expect(text).toContain('wallet')
  })

  it('says it in French when the language is French', async () => {
    const text = await emptySalesTableText('fr')

    expect(text).not.toContain(OLD_EMPTY_HINT)
    expect(text).not.toContain('No sales recorded yet')
    expect(text).toContain(fr.groupIndustry.saleSuggestions.title)
  })
})
