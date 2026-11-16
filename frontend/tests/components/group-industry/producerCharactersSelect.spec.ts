import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ProducerCharactersSelect from '@/components/group-industry/ProducerCharactersSelect.vue'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const CHARACTERS_ENDPOINT = '/me/characters'
const ARIA = { id: 'uuid-aria', eveCharacterId: 2_112_000_001, name: 'Aria Solberg' }
const BRIN = { id: 'uuid-brin', eveCharacterId: 2_112_000_002, name: 'Brin Solberg' }
const CORIN = { id: 'uuid-corin', eveCharacterId: 2_112_000_003, name: 'Corin Vale' }
const LOAD_FAILED = '[data-testid="producer-characters-load-failed"]'

type Props = { modelValue: number[] | null; disabled?: boolean }

async function mountSelect(characters: unknown, props: Props = { modelValue: null }) {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async endpoint => {
    if (endpoint === CHARACTERS_ENDPOINT) return characters
    throw new Error(`Unexpected request ${endpoint}`)
  })
  const wrapper = mount(ProducerCharactersSelect, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
    props,
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountSelect>>

function box(wrapper: Wrapper, eveCharacterId: number) {
  return wrapper.get(`[data-testid="producer-character-${eveCharacterId}"]`)
}

function checkedIds(wrapper: Wrapper): number[] {
  return [ARIA, BRIN, CORIN]
    .filter(character => wrapper.find(`[data-testid="producer-character-${character.eveCharacterId}"]`).exists())
    .filter(character => (box(wrapper, character.eveCharacterId).element as HTMLInputElement).checked)
    .map(character => character.eveCharacterId)
}

enableAutoUnmount(afterEach)

describe('ProducerCharactersSelect', () => {
  it('lists one checkbox per character with its name and ticks all of them when no selection is stored', async () => {
    const wrapper = await mountSelect([ARIA, BRIN])

    expect(wrapper.text()).toContain('Aria Solberg')
    expect(wrapper.text()).toContain('Brin Solberg')
    expect(checkedIds(wrapper)).toEqual([2_112_000_001, 2_112_000_002])
  })

  it('ticks only the characters of the stored selection', async () => {
    const wrapper = await mountSelect([ARIA, BRIN, CORIN], { modelValue: [BRIN.eveCharacterId] })

    expect(checkedIds(wrapper)).toEqual([2_112_000_002])
  })

  it('emits the remaining characters when one of them is unticked from the default selection', async () => {
    const wrapper = await mountSelect([ARIA, BRIN, CORIN])

    await box(wrapper, BRIN.eveCharacterId).setValue(false)

    expect(wrapper.emitted('update:modelValue')).toEqual([[[2_112_000_001, 2_112_000_003]]])
  })

  it('emits the selection plus the character that is ticked, in the order of the characters', async () => {
    const wrapper = await mountSelect([ARIA, BRIN, CORIN], { modelValue: [CORIN.eveCharacterId] })

    await box(wrapper, ARIA.eveCharacterId).setValue(true)

    expect(wrapper.emitted('update:modelValue')).toEqual([[[2_112_000_001, 2_112_000_003]]])
  })

  it('does not let the last ticked character be unticked', async () => {
    const wrapper = await mountSelect([ARIA, BRIN], { modelValue: [ARIA.eveCharacterId] })

    expect(box(wrapper, ARIA.eveCharacterId).attributes('disabled')).toBeDefined()
    expect(box(wrapper, BRIN.eveCharacterId).attributes('disabled')).toBeUndefined()
  })

  it('disables every checkbox when the project cannot be changed', async () => {
    const wrapper = await mountSelect([ARIA, BRIN], { modelValue: null, disabled: true })

    expect(box(wrapper, ARIA.eveCharacterId).attributes('disabled')).toBeDefined()
    expect(box(wrapper, BRIN.eveCharacterId).attributes('disabled')).toBeDefined()
  })

  it('warns instead of listing characters when they cannot be loaded', async () => {
    const wrapper = await mountSelect({ id: 'not-a-character-list' })

    expect(wrapper.find(LOAD_FAILED).exists()).toBe(true)
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
  })
})
