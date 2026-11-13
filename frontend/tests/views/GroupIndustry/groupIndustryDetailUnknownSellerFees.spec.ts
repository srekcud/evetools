import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import GroupIndustryDetail from '@/views/GroupIndustry/GroupIndustryDetail.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { GroupProject } from '@/stores/group-industry/types'
import { expectedMessage } from '../../expectedMessage'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: { id: 'project-sabre-batch' }, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-sabre-batch'
const PROJECT_PATH = `/industry/projects/${PROJECT_ID}`
const FEES_UNKNOWN_HINT_KEY = 'groupIndustry.detail.editFields.feesUnknownHint'
const TYPED_BROKER_FEE_PERCENT = 2.5

const SABRE_BATCH_WITHOUT_SELLER_FEES: GroupProject = {
  id: PROJECT_ID,
  name: 'Sabre batch',
  status: 'selling',
  shortLinkCode: 'sabre242',
  containerName: null,
  ownerCharacterName: 'Aria Solberg',
  ownerCorporationId: 98_000_001,
  membersCount: 3,
  items: [{ typeId: 22_456, typeName: 'Sabre', meLevel: 10, teLevel: 20, runs: 10 }],
  totalBomValue: 480_000_000,
  fulfillmentPercent: 100,
  approvedContributionValue: 480_000_000,
  brokerFeePercent: null,
  salesTaxPercent: null,
  lineRentalRatesOverride: null,
  blacklistGroupIds: [],
  blacklistTypeIds: [],
  createdAt: '2026-10-19T12:00:00+00:00',
  myRole: 'owner',
  myStatus: 'accepted',
  private: false,
  notes: null,
  acceptsOutsideFavoriteSystem: false,
  excludesInvention: false,
  minimumOutsideSaving: 5,
  sellStructureId: null,
  sellStructureName: null,
}

async function openEditModal(): Promise<VueWrapper> {
  const projectStore = useGroupProjectStore()
  vi.spyOn(projectStore, 'fetchProjectDetail').mockResolvedValue(undefined as never)
  vi.spyOn(projectStore, 'fetchBom').mockResolvedValue(undefined as never)
  vi.spyOn(projectStore, 'fetchCockpit').mockResolvedValue(undefined as never)
  projectStore.currentProject = SABRE_BATCH_WITHOUT_SELLER_FEES
  const wrapper = mount(GroupIndustryDetail, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        IndustryModuleTabs: true,
        teleport: true,
        BomTable: true,
        JobsTable: true,
        ContainerBanner: true,
        ProjectCourier: true,
        ContributeModal: true,
        ContributionTable: true,
        FinancialSummary: true,
        DistributionTable: true,
        MemberTable: true,
        PendingRequestsTable: true,
        ShareLinkCard: true,
        LoadingSpinner: true,
        ConfirmModal: true,
      },
    },
  })
  await flushPromises()
  await wrapper.get(`button[aria-label="${en.groupIndustry.detail.edit}"]`).trigger('click')
  await flushPromises()
  return wrapper
}

function feeInputs(wrapper: VueWrapper): [DOMWrapper<HTMLInputElement>, DOMWrapper<HTMLInputElement>] {
  const brokerFee = wrapper.findAll('label').find(label => label.text() === en.groupIndustry.detail.editFields.brokerFee)
  const salesTax = wrapper.findAll('label').find(label => label.text() === en.groupIndustry.detail.editFields.salesTax)
  if (brokerFee === undefined || salesTax === undefined) throw new Error('fee fields not found')
  const byLabel = (label: typeof brokerFee) => wrapper.get<HTMLInputElement>(`#${label.attributes('for')}`) as DOMWrapper<HTMLInputElement>
  return [byLabel(brokerFee), byLabel(salesTax)]
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async () => ({}))
})

describe('group project edit modal with unknown seller fees', () => {
  it('shows the fee fields empty with the unknown hint, never NaN or 0 %', async () => {
    const wrapper = await openEditModal()

    const [brokerFee, salesTax] = feeInputs(wrapper)
    expect({
      values: [brokerFee.element.value, salesTax.element.value],
      hint: wrapper.text().includes(expectedMessage('en', FEES_UNKNOWN_HINT_KEY)),
      garbage: /NaN|0\s?%/.test(wrapper.text()),
    }).toEqual({ values: ['', ''], hint: true, garbage: false })
  })

  it('sends null, not an empty string, for a fee the user leaves empty, and keeps the typed one', async () => {
    const wrapper = await openEditModal()
    const [brokerFee, salesTax] = feeInputs(wrapper)

    await brokerFee.setValue(String(TYPED_BROKER_FEE_PERCENT))
    await salesTax.setValue('')
    const saveButton = wrapper.findAll('button').find(button => button.text() === en.common.actions.save)
    if (saveButton === undefined) throw new Error('no save button')
    await saveButton.trigger('click')
    await flushPromises()

    const patchCall = vi.mocked(apiRequest).mock.calls.find(([endpoint, options]) => endpoint === PROJECT_PATH && options?.method === 'PATCH')
    if (patchCall === undefined) throw new Error('no PATCH request')
    expect(JSON.parse(String(patchCall[1]?.body))).toMatchObject({ brokerFeePercent: TYPED_BROKER_FEE_PERCENT, salesTaxPercent: null })
  })
})
