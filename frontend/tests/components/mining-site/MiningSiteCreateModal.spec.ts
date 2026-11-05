import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import MiningSiteCreateModal from '@/components/mining-site/MiningSiteCreateModal.vue'
import { ALDRANETTE, OPERATION_ID, SITE_ID, SITES_PATH, SOLAR_SYSTEMS_PATH, found, i18nFor, siteOf } from '../../views/MiningSite/miningSiteFixtures'

const push = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => ({ params: {}, query: {} })) as never,
  useRouter: (() => ({ push, replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ALDRANETTE_SEARCH = [{ solarSystemId: ALDRANETTE, solarSystemName: 'Aldranette', security: 0.8, regionName: 'Sinq Laison' }]
const SEARCH_DEBOUNCE_MS = 300
const LABEL_MAX_LENGTH = '100'

async function mountModal(operationId?: string) {
  const i18n = i18nFor('en')
  const wrapper = mount(MiningSiteCreateModal, {
    props: { show: true, operationId },
    global: { plugins: [i18n], stubs: { teleport: true, LoadingSpinner: true } },
  })
  await flushPromises()
  return { wrapper, t: i18n.global.t }
}

async function submit(wrapper: VueWrapper): Promise<void> {
  await found(wrapper, '[data-testid="create-form"]').trigger('submit')
  await flushPromises()
}

function postedSites(): unknown[] {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === SITES_PATH && call[1]?.method === 'POST').map(call => JSON.parse(String(call[1]?.body)))
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  setActivePinia(createPinia())
  push.mockReset()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint.startsWith(SOLAR_SYSTEMS_PATH)) return ALDRANETTE_SEARCH as never
    if (endpoint === SITES_PATH) return siteOf({ id: SITE_ID }) as never
    throw new Error(`Unexpected request ${endpoint}`)
  })
})

afterEach(() => {
  vi.useRealTimers()
})

enableAutoUnmount(afterEach)

describe('MiningSiteCreateModal', () => {
  it('requires a label of 100 characters at most, takes an optional solar system found by search and opens the page of the new site', async () => {
    const withSystem = await mountModal()
    await submit(withSystem.wrapper)
    const refusal = withSystem.wrapper.find('[data-testid="create-error"]').exists() ? withSystem.wrapper.find('[data-testid="create-error"]').text() : null
    const postedBeforeLabel = postedSites()
    await found(withSystem.wrapper, 'input[name="label"]').setValue('Belt Aldranette')
    await found(withSystem.wrapper, 'input[name="solarSystem"]').setValue('Aldr')
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS)
    await flushPromises()
    const result = withSystem.wrapper.findAll('button').find(button => button.text() === 'Aldranette')
    await result?.trigger('click')
    await submit(withSystem.wrapper)
    const pushedWithSystem = push.mock.calls.length
    withSystem.wrapper.unmount()
    const withoutSystem = await mountModal()
    await found(withoutSystem.wrapper, 'input[name="label"]').setValue('Anomalie Spodumain')
    await submit(withoutSystem.wrapper)
    const posted = postedSites()

    expect({
      refusal,
      postedBeforeLabel,
      labelMaxLength: found(withoutSystem.wrapper, 'input[name="label"]').attributes('maxlength'),
      resultFound: result !== undefined,
      postedWithSystem: posted[0],
      postedWithoutSystem: { ...(posted[1] as Record<string, unknown>), solarSystemId: (posted[1] as Record<string, unknown> | undefined)?.solarSystemId ?? null },
      pushedWithSystem,
      pushed: push.mock.calls.map(call => call[0]),
    }).toEqual({
      refusal: expect.stringContaining(withSystem.t('miningSite.create.labelRequired')),
      postedBeforeLabel: [],
      labelMaxLength: LABEL_MAX_LENGTH,
      resultFound: true,
      postedWithSystem: { label: 'Belt Aldranette', solarSystemId: ALDRANETTE },
      postedWithoutSystem: { label: 'Anomalie Spodumain', solarSystemId: null },
      pushedWithSystem: 1,
      pushed: [expect.objectContaining({ name: 'mining-site-detail', params: { id: SITE_ID } }), expect.objectContaining({ name: 'mining-site-detail', params: { id: SITE_ID } })],
    })
  })

  it('sends the operation with the new site and, when the server refuses it, shows the refusal in the window which stays open', async () => {
    vi.mocked(apiRequest).mockRejectedValueOnce(new ApiError('The operation is archived', 400))
    const { wrapper } = await mountModal(OPERATION_ID)
    await found(wrapper, 'input[name="label"]').setValue('Anomalie Spodumain')

    await submit(wrapper)
    const refusal = wrapper.find('[data-testid="create-error"]').exists() ? wrapper.find('[data-testid="create-error"]').text() : null
    const afterRefusal = { closed: wrapper.emitted('close')?.length ?? 0, pushed: push.mock.calls.length }
    await submit(wrapper)

    expect({
      refusal,
      afterRefusal,
      posted: postedSites(),
      closedAfterSuccess: wrapper.emitted('close')?.length ?? 0,
    }).toEqual({
      refusal: expect.stringContaining('The operation is archived'),
      afterRefusal: { closed: 0, pushed: 0 },
      posted: [
        { label: 'Anomalie Spodumain', solarSystemId: null, operationId: OPERATION_ID },
        { label: 'Anomalie Spodumain', solarSystemId: null, operationId: OPERATION_ID },
      ],
      closedAfterSuccess: 1,
    })
  })
})
