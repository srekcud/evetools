import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContributeModal from '@/components/group-industry/ContributeModal.vue'
import { apiRequest } from '@/services/api'
import {
  bomLine,
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  NEXT_ACTION_BUTTON,
  nyxProject,
  route,
  tabSelector,
  textOf,
} from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SOURCING_SECTION = '[data-testid="material-sourcing-section"]'
const CONTRIBUTE_BUTTON = '[data-testid="next-action-contribute"]'
const MORPHITE_LINE_ID = 'line-morphite'

const SOURCE_MORPHITE = nextAction({ code: 'source_material', blocked: true, tab: 'materials', bomLineId: MORPHITE_LINE_ID, typeId: 11_399, typeName: 'Morphite', quantity: 340 })
const LAUNCH_NYX_COPY = nextAction({ code: 'launch_job', tab: 'materials', bomLineId: 'job-nyx-copy', typeId: 23_914, typeName: 'Nyx Blueprint Copy', quantity: 7 })
const MORPHITE_LINE = bomLine({ id: MORPHITE_LINE_ID, typeId: 11_399, typeName: 'Morphite', requiredQuantity: 500, fulfilledQuantity: 160, remainingQuantity: 340 })

const scrollIntoView = vi.fn()

async function click(wrapper: VueWrapper, selector: string): Promise<void> {
  await wrapper.get(selector).trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
  scrollIntoView.mockReset()
  Element.prototype.scrollIntoView = scrollIntoView
})

describe('project cockpit source material action', () => {
  it('leads to the sourcing section from the primary button without opening the contribution modal', async () => {
    const wrapper = await mountCockpitDetail({
      project: nyxProject('in_progress'),
      bom: [MORPHITE_LINE],
      cockpit: cockpitOf('in_progress', SOURCE_MORPHITE),
    })
    const modal = () => wrapper.findComponent(ContributeModal)

    await click(wrapper, NEXT_ACTION_BUTTON)

    const section = wrapper.find(SOURCING_SECTION)
    expect({
      primaryLabel: textOf(wrapper, NEXT_ACTION_BUTTON),
      bomTabSelected: wrapper.get(tabSelector('bom')).attributes('aria-selected'),
      scrolled: scrollIntoView.mock.contexts,
      modalShown: modal().props('show'),
    }).toEqual({
      primaryLabel: en.groupIndustry.cockpit.nextAction.source_material.button,
      bomTabSelected: 'true',
      scrolled: section.exists() ? [section.element] : [],
      modalShown: false,
    })
    expect(en.groupIndustry.cockpit.nextAction.source_material.button).not.toBe(en.groupIndustry.reservation.contribute)
    expect(fr.groupIndustry.cockpit.nextAction.source_material.button).not.toBe(fr.groupIndustry.reservation.contribute)
  })

  it('keeps the declaration as a secondary action that opens the modal prefilled with the line to source', async () => {
    const wrapper = await mountCockpitDetail({
      project: nyxProject('in_progress'),
      bom: [bomLine({ id: 'line-pyerite', typeId: 35, typeName: 'Pyerite' }), MORPHITE_LINE],
      cockpit: cockpitOf('in_progress', SOURCE_MORPHITE),
    })
    const modal = () => wrapper.findComponent(ContributeModal)
    const beforeClick = modal().props('show')

    await click(wrapper, CONTRIBUTE_BUTTON)

    expect({ beforeClick, afterClick: modal().props('show'), prefilled: modal().props('prefilledBomItem') }).toEqual({
      beforeClick: false,
      afterClick: true,
      prefilled: MORPHITE_LINE,
    })
  })

  it('labels the secondary action like the line action, "I supplied it" on a solo project', async () => {
    const group = await mountCockpitDetail({
      project: nyxProject('in_progress'),
      bom: [MORPHITE_LINE],
      cockpit: cockpitOf('in_progress', SOURCE_MORPHITE),
    })
    const groupLabel = textOf(group, CONTRIBUTE_BUTTON)
    group.unmount()

    const solo = await mountCockpitDetail({
      project: nyxProject('in_progress', 'owner', { solo: true }),
      bom: [MORPHITE_LINE],
      cockpit: cockpitOf('in_progress', SOURCE_MORPHITE),
    })
    const soloLabel = textOf(solo, CONTRIBUTE_BUTTON)
    solo.unmount()

    const french = await mountCockpitDetail({
      project: nyxProject('in_progress', 'owner', { solo: true }),
      bom: [MORPHITE_LINE],
      cockpit: cockpitOf('in_progress', SOURCE_MORPHITE),
    }, 'fr')

    expect({ groupLabel, soloLabel, frenchSoloLabel: textOf(french, CONTRIBUTE_BUTTON) }).toEqual({
      groupLabel: en.groupIndustry.reservation.contribute,
      soloLabel: en.groupIndustry.reservation.contributeSolo,
      frenchSoloLabel: fr.groupIndustry.reservation.contributeSolo,
    })
  })

  it('offers no secondary declaration on the other next actions', async () => {
    const wrapper = await mountCockpitDetail({
      project: nyxProject('in_progress'),
      bom: [MORPHITE_LINE],
      cockpit: cockpitOf('in_progress', LAUNCH_NYX_COPY),
    })

    expect({ secondary: wrapper.find(CONTRIBUTE_BUTTON).exists(), primary: wrapper.find(NEXT_ACTION_BUTTON).exists() }).toEqual({ secondary: false, primary: true })
  })
})
