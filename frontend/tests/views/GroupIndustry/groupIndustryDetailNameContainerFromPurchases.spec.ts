import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import GroupIndustryDetail from '@/views/GroupIndustry/GroupIndustryDetail.vue'
import { apiRequest } from '@/services/api'
import type { GroupMemberRole, ProjectPurchasesToDeliver } from '@/stores/group-industry/types'
import { answerApi, cockpitOf, nextAction, nyxProject, PROJECT_PATH, route } from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const DELIVERIES_PATH = `${PROJECT_PATH}/purchases-to-deliver`
const NAME_CONTAINER_BUTTON = '[data-testid="deliveries-name-container"]'

const NO_CONTAINER_DELIVERIES: ProjectPurchasesToDeliver = {
  id: 'project-nyx-batch',
  stockState: 'none',
  purchaseCount: 1,
  rows: [{
    bomLineId: 'line-tritanium',
    typeId: 34,
    typeName: 'Tritanium',
    originKind: 'jita',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    purchasedQuantity: 1000,
    missingQuantity: null,
    deliveryState: 'unknown',
  }],
}

async function mountProjectWithoutContainer(myRole: GroupMemberRole): Promise<VueWrapper> {
  const project = nyxProject('in_progress', myRole, { containerNames: [] })
  answerApi({ project, cockpit: cockpitOf('in_progress', nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 })) })
  const answerOthers = vi.mocked(apiRequest).getMockImplementation()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => (
    endpoint === DELIVERIES_PATH ? NO_CONTAINER_DELIVERIES : answerOthers?.(endpoint, options)
  ))
  const wrapper = mount(GroupIndustryDetail, {
    attachTo: document.body,
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        IndustryModuleTabs: true,
        teleport: true,
        BomTable: true,
        ContainerBanner: true,
        ProjectCourier: true,
        ContributeModal: true,
        ContributionTable: true,
        SalesTable: true,
        SaleSuggestionsPanel: true,
        ContractSaleSuggestions: true,
        ProjectSurplus: true,
        ProjectResearch: true,
        ProjectStructureHealth: true,
        SellForecastCard: true,
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
  return wrapper
}

function containersField(wrapper: VueWrapper): HTMLTextAreaElement | undefined {
  return wrapper
    .findAll('textarea')
    .map(field => field.element)
    .find(field => field.getAttribute('placeholder') === en.groupIndustry.detail.editFields.containerPlaceholder)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = { tab: 'bom' }
  vi.mocked(apiRequest).mockReset()
})

describe('group project detail naming the container from the purchases to deliver', () => {
  it('opens the edit modal with the container names field focused when the owner clicks the button', async () => {
    const wrapper = await mountProjectWithoutContainer('owner')

    expect(containersField(wrapper)).toBeUndefined()
    await wrapper.get(NAME_CONTAINER_BUTTON).trigger('click')
    await flushPromises()

    const field = containersField(wrapper)
    expect(field).toBeDefined()
    expect(document.activeElement).toBe(field)
  })

  it('does not offer the button to a member who cannot edit the project', async () => {
    const wrapper = await mountProjectWithoutContainer('member')

    expect(wrapper.find('[data-testid="deliveries-stock-notice"]').exists()).toBe(true)
    expect(wrapper.find(NAME_CONTAINER_BUTTON).exists()).toBe(false)
  })
})
