import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import en from '@/i18n/locales/en.json'
import BomTable from '@/components/group-industry/BomTable.vue'
import ContributeModal from '@/components/group-industry/ContributeModal.vue'
import ContributionTable from '@/components/group-industry/ContributionTable.vue'
import SalesTable from '@/components/group-industry/SalesTable.vue'
import { apiRequest } from '@/services/api'
import type { ProjectNextAction } from '@/stores/group-industry/types'
import { TEST_LOCALES } from '../../localeTexts'
import {
  attributeOf,
  bomLine,
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  NEXT_ACTION_BUTTON,
  NEXT_ACTION_CARD,
  NEXT_ACTION_TITLE,
  nyxProject,
  route,
  sentPatchBodies,
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

const MORPHITE_LINE_ID = 'line-morphite'

const SOURCE_MORPHITE = nextAction({ code: 'source_material', blocked: true, tab: 'materials', bomLineId: MORPHITE_LINE_ID, typeId: 11_399, typeName: 'Morphite', quantity: 340 })
const LAUNCH_NYX_COPY = nextAction({ code: 'launch_job', tab: 'materials', bomLineId: 'job-nyx-copy', typeId: 23_914, typeName: 'Nyx Blueprint Copy', quantity: 7 })
const RECORD_SALE = nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 })
const REVIEW_CONTRIBUTIONS = nextAction({ code: 'review_contributions', tab: 'contributions', quantity: 2 })
const NOTHING_TO_DO = nextAction({ code: 'nothing_to_do' })

async function clickNextActionButton(wrapper: VueWrapper): Promise<void> {
  const button = wrapper.find(NEXT_ACTION_BUTTON)
  if (button.exists()) await button.trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('project cockpit next action card', () => {
  it('gives each next action its own title with the type and the quantity, a button unless there is nothing to do, amber when blocked', async () => {
    const cases: Array<{ action: ProjectNextAction; button: boolean; blocked: string }> = [
      { action: SOURCE_MORPHITE, button: true, blocked: 'true' },
      { action: LAUNCH_NYX_COPY, button: true, blocked: 'false' },
      { action: RECORD_SALE, button: true, blocked: 'false' },
      { action: REVIEW_CONTRIBUTIONS, button: true, blocked: 'false' },
      { action: NOTHING_TO_DO, button: false, blocked: 'false' },
    ]
    const observed: Record<string, { titles: number; rawKeys: number; buttons: boolean[]; blocked: Array<string | undefined>; morphite: boolean; sale: boolean; review: boolean }> = {}

    for (const locale of TEST_LOCALES) {
      const wrappers: VueWrapper[] = []
      for (const { action } of cases) {
        wrappers.push(await mountCockpitDetail({
          project: nyxProject('in_progress'),
          cockpit: cockpitOf('in_progress', action),
        }, locale))
      }
      const titles = wrappers.map(wrapper => textOf(wrapper, NEXT_ACTION_TITLE))
      observed[locale] = {
        titles: new Set(titles.filter(title => title !== '')).size,
        rawKeys: titles.filter(title => title.includes('groupIndustry.')).length,
        buttons: wrappers.map(wrapper => wrapper.find(NEXT_ACTION_BUTTON).exists()),
        blocked: wrappers.map(wrapper => attributeOf(wrapper, NEXT_ACTION_CARD, 'data-blocked')),
        morphite: /Morphite/.test(textOf(wrappers[0], NEXT_ACTION_CARD)) && /340/.test(textOf(wrappers[0], NEXT_ACTION_CARD)),
        sale: /\b40\b/.test(textOf(wrappers[2], NEXT_ACTION_CARD)),
        review: /\b2\b/.test(textOf(wrappers[3], NEXT_ACTION_CARD)),
      }
      wrappers.forEach(wrapper => wrapper.unmount())
    }

    const expected = { titles: 5, rawKeys: 0, buttons: cases.map(({ button }) => button), blocked: cases.map(({ blocked }) => blocked), morphite: true, sale: true, review: true }
    expect(observed).toEqual({ fr: expected, en: expected })
  })

  it('moves the project to the target status of the action, asking for a confirmation before completing it', async () => {
    const starting = await mountCockpitDetail({
      project: nyxProject('published'),
      cockpit: cockpitOf('published', nextAction({ code: 'start_project', targetStatus: 'in_progress' })),
    })
    await clickNextActionButton(starting)
    const startPatches = sentPatchBodies()
    starting.unmount()
    vi.mocked(apiRequest).mockClear()

    const completing = await mountCockpitDetail({
      project: nyxProject('selling'),
      cockpit: cockpitOf('selling', nextAction({ code: 'complete_project', targetStatus: 'completed' })),
    })
    await clickNextActionButton(completing)
    const beforeConfirmation = { patches: sentPatchBodies(), confirmation: completing.text().includes(en.groupIndustry.detail.completeConfirm.message) }
    const confirmButton = completing.findAll('button').find(button => button.text() === en.groupIndustry.detail.completeConfirm.confirm)
    if (confirmButton !== undefined) await confirmButton.trigger('click')
    await flushPromises()

    expect({ startPatches, beforeConfirmation, afterConfirmation: sentPatchBodies() }).toEqual({
      startPatches: [{ status: 'in_progress' }],
      beforeConfirmation: { patches: [], confirmation: true },
      afterConfirmation: [{ status: 'completed' }],
    })
  })

  it('opens the tab of the action when it has no target status', async () => {
    const selling = await mountCockpitDetail({
      project: nyxProject('selling'),
      cockpit: cockpitOf('selling', RECORD_SALE),
    })
    const beforeClick = { sales: selling.findComponent(SalesTable).exists(), bom: selling.findComponent(BomTable).exists() }
    await clickNextActionButton(selling)
    const afterSaleClick = { sales: selling.findComponent(SalesTable).exists(), bom: selling.findComponent(BomTable).exists() }
    selling.unmount()

    const reviewing = await mountCockpitDetail({
      project: nyxProject('published'),
      cockpit: cockpitOf('published', REVIEW_CONTRIBUTIONS),
    })
    await clickNextActionButton(reviewing)

    expect({ beforeClick, afterSaleClick, contributions: reviewing.findComponent(ContributionTable).exists() }).toEqual({
      beforeClick: { sales: false, bom: true },
      afterSaleClick: { sales: true, bom: false },
      contributions: true,
    })
  })

  it('opens the contribution modal prefilled with the material line to source when it is in the BOM', async () => {
    const morphiteLine = bomLine({ id: MORPHITE_LINE_ID, typeId: 11_399, typeName: 'Morphite', requiredQuantity: 500, fulfilledQuantity: 160, remainingQuantity: 340 })
    const wrapper = await mountCockpitDetail({
      project: nyxProject('in_progress'),
      bom: [bomLine({ id: 'line-pyerite', typeId: 35, typeName: 'Pyerite' }), morphiteLine],
      cockpit: cockpitOf('in_progress', SOURCE_MORPHITE),
    })
    const modal = () => wrapper.findComponent(ContributeModal)
    const beforeClick = modal().props('show')

    await clickNextActionButton(wrapper)

    expect({ beforeClick, afterClick: modal().props('show'), prefilled: modal().props('prefilledBomItem') }).toEqual({
      beforeClick: false,
      afterClick: true,
      prefilled: morphiteLine,
    })
  })
})
