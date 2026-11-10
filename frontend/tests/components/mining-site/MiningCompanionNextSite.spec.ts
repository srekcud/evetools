import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { apiRequest } from '@/services/api'
import MiningCompanionNextSite from '@/components/mining-site/MiningCompanionNextSite.vue'
import { ALDRANETTE, SOLAR_SYSTEMS_PATH, found, i18nFor } from '../../views/MiningSite/miningSiteFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ALDRANETTE_SEARCH = [{ solarSystemId: ALDRANETTE, solarSystemName: 'Aldranette', security: 0.8, regionName: 'Sinq Laison' }]
const SEARCH_DEBOUNCE_MS = 300
const LABEL_MAX_LENGTH = '100'

function mountForm(props: { failure?: string | null; submitting?: boolean } = {}) {
  const i18n = i18nFor('en')
  const wrapper = mount(MiningCompanionNextSite, {
    props: { failure: null, submitting: false, ...props },
    global: { plugins: [i18n], stubs: { LoadingSpinner: true } },
  })
  return { wrapper, t: i18n.global.t }
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint.startsWith(SOLAR_SYSTEMS_PATH)) return ALDRANETTE_SEARCH as never
    throw new Error(`Unexpected request ${endpoint}`)
  })
})

afterEach(() => {
  vi.useRealTimers()
})

enableAutoUnmount(afterEach)

describe('MiningCompanionNextSite', () => {
  it('limits the label to 100 characters, warns that the active sites will be closed and emits the label with the solar system found by search or null', async () => {
    const { wrapper, t } = mountForm()

    const labelMaxLength = found(wrapper, 'input[name="label"]').attributes('maxlength')
    const closesActive = wrapper.text().includes(t('miningSite.create.closesActive'))
    await found(wrapper, 'input[name="label"]').setValue('  Belt Jaspet ')
    await found(wrapper, '[data-testid="companion-next-site-form"]').trigger('submit')
    await found(wrapper, 'input[name="solarSystem"]').setValue('Aldr')
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS)
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text() === 'Aldranette')?.trigger('click')
    await found(wrapper, '[data-testid="companion-next-site-form"]').trigger('submit')

    expect({ labelMaxLength, closesActive, emitted: wrapper.emitted('submit') }).toEqual({
      labelMaxLength: LABEL_MAX_LENGTH,
      closesActive: true,
      emitted: [[{ label: 'Belt Jaspet', solarSystemId: null }], [{ label: 'Belt Jaspet', solarSystemId: ALDRANETTE }]],
    })
  })

  it('shows the required label message and emits nothing when the label is empty or blank', async () => {
    const { wrapper, t } = mountForm()

    await found(wrapper, '[data-testid="companion-next-site-form"]').trigger('submit')
    const afterEmpty = wrapper.find('[data-testid="companion-next-site-error"]').exists() ? wrapper.find('[data-testid="companion-next-site-error"]').text() : null
    await found(wrapper, 'input[name="label"]').setValue('   ')
    await found(wrapper, '[data-testid="companion-next-site-form"]').trigger('submit')

    expect({ afterEmpty, emitted: wrapper.emitted('submit') ?? [] }).toEqual({
      afterEmpty: t('miningSite.create.labelRequired'),
      emitted: [],
    })
  })

  it('shows the failure of the server and disables the submit button while submitting', () => {
    const idle = mountForm({ failure: 'Only the site owner or an operation admin can close this site' })
    const busy = mountForm({ submitting: true })

    expect({
      failure: idle.wrapper.find('[data-testid="companion-next-site-error"]').exists() ? idle.wrapper.find('[data-testid="companion-next-site-error"]').text() : null,
      idleDisabled: found(idle.wrapper, '[data-testid="companion-next-site-submit"]').attributes('disabled'),
      busyError: busy.wrapper.find('[data-testid="companion-next-site-error"]').exists(),
      busyDisabled: found(busy.wrapper, '[data-testid="companion-next-site-submit"]').attributes('disabled'),
    }).toEqual({
      failure: 'Only the site owner or an operation admin can close this site',
      idleDisabled: undefined,
      busyError: false,
      busyDisabled: '',
    })
  })

  it('emits cancel when the cancel button is clicked and nothing else', async () => {
    const { wrapper } = mountForm()

    await found(wrapper, '[data-testid="companion-next-site-cancel"]').trigger('click')

    expect({ cancel: wrapper.emitted('cancel'), submit: wrapper.emitted('submit') ?? [] }).toEqual({ cancel: [[]], submit: [] })
  })
})
