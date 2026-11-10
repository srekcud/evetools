import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import Appraisal from '@/views/Appraisal.vue'
import AppraisalInputPanel from '@/components/appraisal/AppraisalInputPanel.vue'
import { apiRequest, authFetch } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
  authFetch: vi.fn(),
}))

const PASTED_ITEMS = 'Tritanium 12000\nPyerite 4500'
const CHOSEN_STRUCTURE = { id: 1_035_466_617_946, name: 'C-J6MT - 1st Taj Mahgoon' }
const APPRAISE_ENDPOINT = '/api/shopping-list/appraise'
const PARSE_ENDPOINT = '/api/shopping-list/parse'

function jsonResponse(body: unknown): Response {
  return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })
}

function postedBodyTo(endpoint: string): Record<string, unknown> {
  const call = vi.mocked(authFetch).mock.calls.find(([input]) => String(input) === endpoint)
  if (!call) throw new Error(`No request to ${endpoint}`)
  return JSON.parse(String(call[1]?.body)) as Record<string, unknown>
}

async function analyzeWithChosenStructure(): Promise<VueWrapper> {
  const wrapper = mount(Appraisal, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        RouterLink: true,
        ErrorBanner: true,
        AppraisalInputPanel: true,
        AppraisalResultsTable: true,
        AppraisalActions: true,
        MissingVolumeWarning: true,
      },
    },
  })
  await flushPromises()
  const inputPanel = wrapper.findComponent(AppraisalInputPanel)
  inputPanel.vm.$emit('update:selected-structure', CHOSEN_STRUCTURE)
  inputPanel.vm.$emit('update:input-text', PASTED_ITEMS)
  await flushPromises()
  inputPanel.vm.$emit('analyze')
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue(null)
  vi.mocked(authFetch).mockReset()
  vi.mocked(authFetch).mockImplementation(async (input: RequestInfo | URL) => {
    const url = String(input)
    if (url === APPRAISE_ENDPOINT) return jsonResponse({ items: [], notFound: [], totals: {}, priceError: null, missingVolumeTypeIds: [] })
    if (url === PARSE_ENDPOINT) return jsonResponse({ items: [], notFound: [], totals: {}, structureId: CHOSEN_STRUCTURE.id })
    throw new Error(`Unexpected request ${url}`)
  })
})

describe('Appraisal structure comparison', () => {
  it('posts the chosen structure with the pasted text to the structure comparison endpoint', async () => {
    await analyzeWithChosenStructure()

    expect(postedBodyTo(PARSE_ENDPOINT)).toMatchObject({ text: PASTED_ITEMS, structureId: CHOSEN_STRUCTURE.id })
  })

  it('posts only the pasted text to the appraise endpoint', async () => {
    await analyzeWithChosenStructure()

    expect(postedBodyTo(APPRAISE_ENDPOINT)).toEqual({ text: PASTED_ITEMS })
  })
})
