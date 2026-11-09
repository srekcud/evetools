import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ContractSaleSuggestions from '@/components/group-industry/ContractSaleSuggestions.vue'
import { apiRequest } from '@/services/api'
import type { ContractSaleSuggestions as ContractSaleSuggestionsData } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-ishtar'
const SUGGESTIONS_PATH = `/industry/projects/${PROJECT_ID}/contract-suggestions`
const LINK_PATH = `/industry/projects/${PROJECT_ID}/sales/from-contract`
const ROW = (contractId: number) => `[data-testid="contract-suggestion-${contractId}"]`
const LINK_BUTTON = (contractId: number) => `[data-testid="link-contract-${contractId}"]`

const ISHTAR_CONTRACT = {
  contractId: 601_001,
  typeId: 12_005,
  typeName: 'Ishtar',
  quantity: 2,
  price: 3_000_000,
  unitPrice: 1_500_000,
  completedAt: '2026-11-09T19:00:00+00:00',
  sellerCharacterId: 2_500_000_001,
  sellerCharacterName: 'Aria Solberg',
  contractFees: 138_000 as number | null,
}

function suggestions(overrides: Partial<ContractSaleSuggestionsData> = {}): ContractSaleSuggestionsData {
  return { projectId: PROJECT_ID, candidates: [ISHTAR_CONTRACT], unreadableCharacters: [], ...overrides }
}

enableAutoUnmount(afterEach)

async function mountWith(data: ContractSaleSuggestionsData, props: { recordingDisabled?: boolean } = {}) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => (endpoint === SUGGESTIONS_PATH ? data : undefined))
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(ContractSaleSuggestions, { props: { projectId: PROJECT_ID, ...props }, global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

describe('ContractSaleSuggestions', () => {
  it('lists each finished contract that sold an item of the project with its seller and its price', async () => {
    const wrapper = await mountWith(suggestions())

    const row = wrapper.get(ROW(601_001)).text()
    expect(row).toContain('Ishtar')
    expect(row).toContain('Aria Solberg')
    expect(row).toContain('2')
  })

  it('shows the real fees of a contract once the journal gave them and says unknown, never zero, otherwise', async () => {
    const known = await mountWith(suggestions())
    expect(known.find('[data-testid="contract-fees-unknown"]').exists()).toBe(false)

    const unknown = await mountWith(suggestions({ candidates: [{ ...ISHTAR_CONTRACT, contractFees: null }] }))
    expect(unknown.find('[data-testid="contract-fees-unknown"]').exists()).toBe(true)
  })

  it('links the contract to a sale with the contract and its seller then reads the suggestions again', async () => {
    const wrapper = await mountWith(suggestions())

    await wrapper.get(LINK_BUTTON(601_001)).trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith(LINK_PATH, {
      method: 'POST',
      body: JSON.stringify({ contractId: 601_001, characterId: 2_500_000_001 }),
    })
    expect(vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === SUGGESTIONS_PATH)).toHaveLength(2)
  })

  it('disables the linking of a completed project', async () => {
    const wrapper = await mountWith(suggestions(), { recordingDisabled: true })

    expect(wrapper.get(LINK_BUTTON(601_001)).attributes('disabled')).toBeDefined()
  })

  it('warns about the characters whose contracts could not be read', async () => {
    const wrapper = await mountWith(suggestions({ unreadableCharacters: [{ characterId: 2_500_000_002, reason: 'no_token' }] }))

    expect(wrapper.get('[data-testid="contract-unreadable"]').attributes('data-reasons')).toBe('no_token')
  })

  it('says there is no contract to link when the list is empty', async () => {
    const wrapper = await mountWith(suggestions({ candidates: [] }))

    expect(wrapper.find('[data-testid="contract-suggestions-empty"]').exists()).toBe(true)
  })

  it('shows the error of a failed linking and keeps the list', async () => {
    const wrapper = await mountWith(suggestions())
    vi.mocked(apiRequest).mockRejectedValueOnce(new Error('This contract is already linked to a sale.'))

    await wrapper.get(LINK_BUTTON(601_001)).trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="contract-link-error"]').text()).toContain('already linked')
    expect(wrapper.find(ROW(601_001)).exists()).toBe(true)
  })
})
