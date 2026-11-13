import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import SellStructureSelect from '@/components/group-industry/SellStructureSelect.vue'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SETTINGS_ENDPOINT = '/me/settings'
const JITA_4_4 = { id: 60_003_760, name: 'Jita 4-4' }
const KEEPSTAR_A = { id: 1_042_000_000_001, name: 'Keepstar A' }
const FORTIZAR_B = { id: 1_043_000_000_002, name: 'Fortizar B' }
const SELECT = '[data-testid="sell-structure-select"]'
const NONE_NOTICE = '[data-testid="sell-structure-none"]'

type Settings = {
  preferredMarketStructureId: number | null
  preferredMarketStructureName: string | null
  marketStructures: { id: number; name: string }[]
}

type Props = { modelValue: number | null; disabled?: boolean; currentStructure?: { id: number; name: string } | null }

async function mountSelect(settings: Settings, props: Props = { modelValue: null }) {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async endpoint => {
    if (endpoint === SETTINGS_ENDPOINT) return settings
    throw new Error(`Unexpected request ${endpoint}`)
  })
  const wrapper = mount(SellStructureSelect, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
    props,
  })
  await flushPromises()
  return wrapper
}

function optionsOf(wrapper: Awaited<ReturnType<typeof mountSelect>>): [string, string][] {
  return wrapper.get(SELECT).findAll('option').map(option => [option.attributes('value') ?? '', option.text()])
}

enableAutoUnmount(afterEach)

describe('SellStructureSelect', () => {
  it('lists the preferred structure first, once, with a fallback name when it has none, then the other market structures', async () => {
    const wrapper = await mountSelect({
      preferredMarketStructureId: JITA_4_4.id,
      preferredMarketStructureName: null,
      marketStructures: [KEEPSTAR_A, FORTIZAR_B, JITA_4_4],
    })

    expect(optionsOf(wrapper)).toEqual([
      ['60003760', 'Structure 60003760'],
      ['1042000000001', 'Keepstar A'],
      ['1043000000002', 'Fortizar B'],
    ])
  })

  it('selects the preferred structure by emitting its id when no structure is selected yet', async () => {
    const wrapper = await mountSelect({
      preferredMarketStructureId: JITA_4_4.id,
      preferredMarketStructureName: JITA_4_4.name,
      marketStructures: [JITA_4_4, KEEPSTAR_A],
    })

    expect(wrapper.emitted('update:modelValue')).toEqual([[60_003_760]])
  })

  it('selects the first market structure of the list by emitting its id when no structure is preferred and none is selected yet', async () => {
    const wrapper = await mountSelect({
      preferredMarketStructureId: null,
      preferredMarketStructureName: null,
      marketStructures: [KEEPSTAR_A, FORTIZAR_B],
    })

    expect(wrapper.emitted('update:modelValue')).toEqual([[1_042_000_000_001]])
  })

  it('emits nothing for a structure of the list when the project already carries a structure', async () => {
    const wrapper = await mountSelect(
      { preferredMarketStructureId: null, preferredMarketStructureName: null, marketStructures: [KEEPSTAR_A, FORTIZAR_B] },
      { modelValue: FORTIZAR_B.id },
    )

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('replaces the select by a no market structure notice and emits nothing when the user has no structure', async () => {
    const wrapper = await mountSelect({ preferredMarketStructureId: null, preferredMarketStructureName: null, marketStructures: [] })

    expect(wrapper.find(SELECT).exists()).toBe(false)
    expect(wrapper.get(NONE_NOTICE).text()).toBe('No market structure configured')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('keeps the structure already carried by the project listed after the user structures even when it is not among them', async () => {
    const wrapper = await mountSelect(
      { preferredMarketStructureId: JITA_4_4.id, preferredMarketStructureName: JITA_4_4.name, marketStructures: [KEEPSTAR_A] },
      { modelValue: FORTIZAR_B.id, currentStructure: FORTIZAR_B },
    )

    expect(optionsOf(wrapper)).toEqual([
      ['60003760', 'Jita 4-4'],
      ['1042000000001', 'Keepstar A'],
      ['1043000000002', 'Fortizar B'],
    ])
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('shows a load failure notice and no select when the settings cannot be read or have an unexpected shape', async () => {
    vi.mocked(apiRequest).mockReset()
    vi.mocked(apiRequest).mockRejectedValue(new Error('Network down'))
    const failed = mount(SellStructureSelect, {
      global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
      props: { modelValue: null },
    })
    vi.mocked(apiRequest).mockReset()
    vi.mocked(apiRequest).mockResolvedValue({ id: 'unexpected' })
    const malformed = mount(SellStructureSelect, {
      global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
      props: { modelValue: null },
    })
    await flushPromises()

    expect([failed, malformed].map(wrapper => [wrapper.find(SELECT).exists(), wrapper.get('[data-testid="sell-structure-load-failed"]').text(), wrapper.emitted('update:modelValue')])).toEqual([
      [false, 'Market structures could not be loaded', undefined],
      [false, 'Market structures could not be loaded', undefined],
    ])
  })

  it('disables the select when disabled and emits the numeric id of the structure the user picks otherwise', async () => {
    const settings: Settings = {
      preferredMarketStructureId: JITA_4_4.id,
      preferredMarketStructureName: JITA_4_4.name,
      marketStructures: [JITA_4_4, KEEPSTAR_A],
    }
    const locked = await mountSelect(settings, { modelValue: JITA_4_4.id, disabled: true })
    const open = await mountSelect(settings, { modelValue: JITA_4_4.id })

    await open.get(SELECT).setValue('1042000000001')

    expect({
      lockedDisabled: locked.get(SELECT).attributes('disabled') !== undefined,
      openDisabled: open.get(SELECT).attributes('disabled') !== undefined,
      emitted: open.emitted('update:modelValue'),
    }).toEqual({ lockedDisabled: true, openDisabled: false, emitted: [[1_042_000_000_001]] })
  })
})
