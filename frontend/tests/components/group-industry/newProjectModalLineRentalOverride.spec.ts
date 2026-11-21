import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import NewProjectModal from '@/components/group-industry/NewProjectModal.vue'
import { apiRequest } from '@/services/api'
import { present } from '../../present'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECTS_ENDPOINT = '/industry/projects'
const RATES_ENDPOINT = '/industry/line-rental-rates'

const PLAYER_RATES = {
  component_t1: 300,
  module_t1: 12_000,
  ship_t1: 80_000,
  module_t2: 90_000,
  ship_t2: 2_000_000,
  capital_t1: 30_000,
  capital_t2: 110_000,
  location_factor: 750_000,
}

function mountModal(ratesResponse: () => Promise<unknown> = async () => ({ id: 'rates', rates: PLAYER_RATES })) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async endpoint => {
    if (endpoint === RATES_ENDPOINT) return ratesResponse()
    if (endpoint === '/industry/blacklist') return { categories: [], groupIds: [], items: [] }
    if (endpoint.startsWith('/industry/search')) return { results: [{ typeId: 22456, typeName: 'Sabre', isT2: false }] }
    return { id: 'group-project-created' }
  })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(NewProjectModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: true },
  })
}

async function clickButton(wrapper: VueWrapper, label: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === label)
  expect(button, `button ${label}`).toBeDefined()
  await present(button).trigger('click')
}

async function openOverride(wrapper: VueWrapper) {
  await clickButton(wrapper, 'Override for this project')
  await flushPromises()
}

function rateInput(wrapper: VueWrapper, label: string) {
  return wrapper.get(`input[aria-label="${label}"]`)
}

async function createSabreProject(wrapper: VueWrapper): Promise<Record<string, unknown>> {
  await clickButton(wrapper, 'Bulk Paste')
  await wrapper.get('textarea').setValue('Sabre 4 8 x20')
  await clickButton(wrapper, 'Parse & Add')
  await flushPromises()
  await clickButton(wrapper, 'Create Project')
  await flushPromises()
  const creationCall = vi.mocked(apiRequest).mock.calls.find(([endpoint]) => endpoint === PROJECTS_ENDPOINT)
  expect(creationCall, 'project creation request').toBeDefined()
  return JSON.parse(String(present(present(creationCall)[1]).body))
}

describe('NewProjectModal line rental override (issue 1190)', () => {
  it('prefills the override table with the rates of the player', async () => {
    const wrapper = mountModal()
    await flushPromises()

    await openOverride(wrapper)

    expect((rateInput(wrapper, 'Ship T1').element as HTMLInputElement).value).toBe('80000')
    expect((rateInput(wrapper, 'Component T1').element as HTMLInputElement).value).toBe('300')
    expect((rateInput(wrapper, 'Location Factor').element as HTMLInputElement).value).toBe('750000')
  })

  it('sends no override when the table was opened and collapsed without an edit', async () => {
    const wrapper = mountModal()
    await flushPromises()

    await openOverride(wrapper)
    await clickButton(wrapper, 'Collapse')
    const body = await createSabreProject(wrapper)

    expect(body).not.toHaveProperty('lineRentalRatesOverride')
  })

  it('sends no override when the table is still open and nothing was edited', async () => {
    const wrapper = mountModal()
    await flushPromises()

    await openOverride(wrapper)
    const body = await createSabreProject(wrapper)

    expect(body).not.toHaveProperty('lineRentalRatesOverride')
  })

  it('keeps announcing the defaults of the settings until a rate is edited', async () => {
    const wrapper = mountModal()
    await flushPromises()

    await openOverride(wrapper)

    expect(wrapper.text()).toContain('Using defaults from your settings')
    expect(wrapper.text()).not.toContain('Custom rates for this project')
  })

  it('sends every rate of the player with the edited one once a value was edited', async () => {
    const wrapper = mountModal()
    await flushPromises()

    await openOverride(wrapper)
    await rateInput(wrapper, 'Ship T1').setValue('95000')
    const body = await createSabreProject(wrapper)

    expect(body.lineRentalRatesOverride).toEqual({ ...PLAYER_RATES, ship_t1: 95_000 })
    expect(wrapper.text()).toContain('Custom rates for this project')
  })

  it('shows no generic rate when the rates of the player cannot be loaded', async () => {
    const wrapper = mountModal(async () => { throw new Error('rates unavailable') })
    await flushPromises()

    await openOverride(wrapper)

    expect(wrapper.find('input[aria-label="Ship T1"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Your rates could not be loaded')
  })
})
