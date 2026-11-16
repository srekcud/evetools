import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContributeModal from '@/components/group-industry/ContributeModal.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem } from '@/stores/group-industry/types'
import { present } from '../../present'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-1009'
const PREVIEW_DEBOUNCE_MILLISECONDS = 400
const contributeLabels = en.groupIndustry.contribute

function bomLine(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'bom-tritanium',
    typeId: 34,
    typeName: 'Tritanium',
    requiredQuantity: 100,
    fulfilledQuantity: 0,
    remainingQuantity: 100,
    fulfillmentPercent: 0,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: false,
    jobGroup: null,
    activityType: null,
    parentTypeId: null,
    meLevel: null,
    teLevel: null,
    runs: null,
    isFulfilled: false,
    jobMatches: [],
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    reservedQuantity: 0,
    freeQuantity: 0,
    ...overrides,
  }
}

const TRITANIUM = bomLine({})
const PREVIEW_ENDPOINT = `/industry/projects/${PROJECT_ID}/material-value-previews/${TRITANIUM.id}?quantity=100`

async function openModal(line: BomItem): Promise<VueWrapper> {
  useGroupProjectStore().bomItems = [line]
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(ContributeModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: false, projectId: PROJECT_ID, prefilledBomItem: line },
  })
  await wrapper.setProps({ show: true })
  await flushPromises()
  return wrapper
}

async function waitForThePreview(): Promise<void> {
  await vi.advanceTimersByTimeAsync(PREVIEW_DEBOUNCE_MILLISECONDS)
  await flushPromises()
}

function answerPreviewWith(preview: { matchedQuantity: number, matchedValue: number | null, unmatchedQuantity: number }): void {
  vi.mocked(apiRequest).mockImplementation(((endpoint: string) =>
    Promise.resolve(endpoint === PREVIEW_ENDPOINT ? preview : {})) as typeof apiRequest)
}

function submittedBody(): Record<string, unknown> {
  const submissions = vi.mocked(apiRequest).mock.calls.filter(([, options]) => options?.method === 'POST')
  expect(submissions).toHaveLength(1)
  return JSON.parse(String(present(submissions[0])[1]?.body))
}

async function submit(wrapper: VueWrapper): Promise<void> {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === contributeLabels.submit)
  await present(button).trigger('click')
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
})

afterEach(() => {
  vi.useRealTimers()
})

describe('ContributeModal value source of a material', () => {
  it('offers the wallet purchase and the Jita price of the deposit day, the Jita price being the default', async () => {
    answerPreviewWith({ matchedQuantity: 0, matchedValue: null, unmatchedQuantity: 100 })
    const wrapper = await openModal(TRITANIUM)

    const wallet = wrapper.find<HTMLInputElement>('[data-testid="value-source-wallet_purchase"]')
    const jita = wrapper.find<HTMLInputElement>('[data-testid="value-source-jita_price"]')
    expect([wallet.exists(), jita.exists()]).toEqual([true, true])
    expect(jita.element.checked).toBe(true)
    expect(wrapper.text()).toContain(contributeLabels.valueSource.walletPurchase)
  })

  it('sends the chosen wallet purchase source with the contribution', async () => {
    answerPreviewWith({ matchedQuantity: 40, matchedValue: 200, unmatchedQuantity: 60 })
    const wrapper = await openModal(TRITANIUM)

    await wrapper.find('[data-testid="value-source-wallet_purchase"]').setValue(true)
    await submit(wrapper)

    expect(submittedBody()).toMatchObject({ type: 'material', bomItemId: TRITANIUM.id, quantity: 100, valueSource: 'wallet_purchase' })
  })

  it('sends no value source when the member leaves the default Jita price, the backend defaulting to it', async () => {
    answerPreviewWith({ matchedQuantity: 0, matchedValue: null, unmatchedQuantity: 100 })
    const wrapper = await openModal(TRITANIUM)

    await submit(wrapper)

    expect(submittedBody()).toEqual({ type: 'material', bomItemId: TRITANIUM.id, quantity: 100 })
  })

  it('does not offer a value source on a job line', async () => {
    const job = bomLine({ id: 'bom-ishtar', typeName: 'Ishtar', isJob: true, jobGroup: 'final', activityType: 'manufacturing' })
    const wrapper = await openModal(job)

    expect(wrapper.find('[data-testid="value-source-wallet_purchase"]').exists()).toBe(false)
  })
})

describe('ContributeModal wallet preview', () => {
  it('reads the preview of the line and the quantity and shows the units matched in the wallet', async () => {
    answerPreviewWith({ matchedQuantity: 40, matchedValue: 200, unmatchedQuantity: 60 })
    const wrapper = await openModal(TRITANIUM)
    await waitForThePreview()

    expect(vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint)).toContain(PREVIEW_ENDPOINT)
    const preview = wrapper.find('[data-testid="value-preview"]')
    expect(preview.exists()).toBe(true)
    expect(preview.text()).toContain('40 units matched in your wallet')
  })

  it('shows the units awaiting a wallet transaction for the wallet purchase source', async () => {
    answerPreviewWith({ matchedQuantity: 40, matchedValue: 200, unmatchedQuantity: 60 })
    const wrapper = await openModal(TRITANIUM)

    await wrapper.find('[data-testid="value-source-wallet_purchase"]').setValue(true)
    await waitForThePreview()

    expect(wrapper.find('[data-testid="value-preview"]').text()).toContain('60 units awaiting a wallet transaction')
  })

  it('shows the units valued at the Jita price for the Jita price source', async () => {
    answerPreviewWith({ matchedQuantity: 40, matchedValue: 200, unmatchedQuantity: 60 })
    const wrapper = await openModal(TRITANIUM)
    await waitForThePreview()

    expect(wrapper.find('[data-testid="value-preview"]').text()).toContain('60 units valued at the Jita price')
  })

  it('says no purchase is read yet when nothing matches, without a zero value', async () => {
    answerPreviewWith({ matchedQuantity: 0, matchedValue: null, unmatchedQuantity: 100 })
    const wrapper = await openModal(TRITANIUM)
    await waitForThePreview()

    const preview = wrapper.find('[data-testid="value-preview"]')
    expect(preview.text()).toContain(contributeLabels.valuePreview.noneMatched)
    expect(preview.text()).not.toContain('0 ISK')
  })

  it('shows a message when the preview cannot be read and keeps the submit possible', async () => {
    vi.mocked(apiRequest).mockImplementation(((endpoint: string) =>
      endpoint === PREVIEW_ENDPOINT ? Promise.reject(new Error('boom')) : Promise.resolve({})) as typeof apiRequest)
    const wrapper = await openModal(TRITANIUM)
    await waitForThePreview()

    expect(wrapper.find('[data-testid="value-preview-error"]').text()).toContain(contributeLabels.valuePreview.loadError)
    const submitButton = wrapper.findAll<HTMLButtonElement>('button').find(candidate => candidate.text() === contributeLabels.submit)
    expect(present(submitButton).element.disabled).toBe(false)
  })
})
