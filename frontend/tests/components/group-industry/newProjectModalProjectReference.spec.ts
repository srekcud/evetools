import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import NewProjectModal from '@/components/group-industry/NewProjectModal.vue'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const REFERENCES_ENDPOINT = '/industry/project-references'
const ISHTAR = { typeId: 12005, typeName: 'Ishtar' }
const SABRE = { typeId: 22456, typeName: 'Sabre' }
const ISHTAR_REFERENCE = {
  productTypeId: ISHTAR.typeId,
  projectId: 'project-ishtar-batch',
  completedAt: '2026-11-02T10:00:00+00:00',
  units: 100,
  unitCost: 20_000,
  marginPercent: 12.5,
}

type Wrapper = Awaited<ReturnType<typeof mountModal>>

async function mountModal(references: unknown[] | Error, locale: 'en' | 'fr' = 'en') {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async endpoint => {
    if (endpoint === REFERENCES_ENDPOINT) {
      if (references instanceof Error) throw references
      return { id: 'project-references', rows: references }
    }
    return []
  })
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(NewProjectModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: false, prefill: ISHTAR },
  })
  await wrapper.setProps({ show: true })
  await flushPromises()

  return wrapper
}

function referenceText(wrapper: Wrapper): string {
  return wrapper.get('[data-testid="project-reference"]').text().replace(/[\u00a0\u202f]/g, ' ')
}

describe('NewProjectModal project reference', () => {
  it('reads the references when the modal opens', async () => {
    await mountModal([ISHTAR_REFERENCE])

    expect(vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint)).toContain(REFERENCES_ENDPOINT)
  })

  it('shows the finish date, the real unit cost and the margin under the product that has a reference', async () => {
    const wrapper = await mountModal([ISHTAR_REFERENCE])

    const text = referenceText(wrapper)
    expect(text).toContain('02/11/26')
    expect(text).toContain('20,000 ISK')
    expect(text).toContain('12.5 %')
  })

  it('shows an unknown unit cost and margin as unknown, never as zero', async () => {
    const wrapper = await mountModal([{ ...ISHTAR_REFERENCE, unitCost: null, marginPercent: null }])

    const text = referenceText(wrapper)
    expect(text.match(/unknown/gi)).toHaveLength(2)
    expect(text).not.toContain('0 ISK')
    expect(text).not.toContain('0.0 %')
  })

  it('shows nothing for a product without reference', async () => {
    const wrapper = await mountModal([{ ...ISHTAR_REFERENCE, productTypeId: SABRE.typeId }])

    expect(wrapper.find('[data-testid="project-reference"]').exists()).toBe(false)
  })

  it('shows the reference in French with the French labels', async () => {
    const wrapper = await mountModal([ISHTAR_REFERENCE], 'fr')

    const text = referenceText(wrapper)
    expect(text).toContain('20 000 ISK')
    expect(text).toContain('Référence')
  })

  it('shows a discreet error when the references cannot be read and keeps the creation available', async () => {
    const wrapper = await mountModal(new Error('References unavailable'))

    expect(wrapper.get('[data-testid="project-reference-error"]').text()).toContain('References unavailable')
    const createButton = wrapper.findAll('button').find(candidate => candidate.text() === 'Create Project')
    expect(createButton?.attributes('disabled')).toBeUndefined()
  })
})
