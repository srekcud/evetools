import { enableAutoUnmount, flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createApp } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectFlowCardDetail from '@/components/group-industry/ProjectFlowCardDetail.vue'
import type { FlowCard } from '@/components/group-industry/projectFlow'
import { useFormatters } from '@/composables/useFormatters'
import { apiRequest } from '@/services/api'
import type { BomItem, GroupProject, SourcingDecisions, SourcingRow } from '@/stores/group-industry/types'
import { deferred } from '../../deferred'
import { messageOf } from '../../localeTexts'
import { sourcingDecisions, sourcingOption, sourcingRow } from '../../projectSetupFixtures'
import { bomLineOf, COMPONENT_X, flowCard, ISHTAR, projectOf, TRITANIUM } from './projectFlowFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const PROJECT_ID = 'project-ishtar-batch'
const PROJECT_PATH = `/industry/projects/${PROJECT_ID}`
const DECISIONS_PATH = `${PROJECT_PATH}/sourcing-decisions`
const BLOCK = '[data-testid="flow-detail-block-buyOrBuild"]'
const STANDALONE_ZERO = /(^|[^\d.,])0([^\d.,]|$)/
const WORKED_LINE_REFUSAL = 'The line "Capital Armor Plates" carries a linked job, a purchase or a contribution and cannot be removed from the plan.'
const UNKNOWN_LABEL = messageOf('en', 'groupIndustry.flow.detail.unknown').toLowerCase()
const OPTIONS = ['build', 'jita', 'structure'] as const

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

function formattersOf(): ReturnType<typeof useFormatters> {
  let formatters!: ReturnType<typeof useFormatters>
  const app = createApp({
    setup: () => {
      formatters = useFormatters()
      return () => null
    },
  })
  app.use(createI18n({ legacy: false, locale: 'en', messages: { en, fr } }))
  app.mount(document.createElement('div'))
  app.unmount()
  return formatters
}

const { formatIsk, formatNumber } = formattersOf()

function squashed(text: string): string {
  return text.replace(/[\u00a0\u202f]/g, ' ').replace(/\s+/g, ' ').trim()
}

function decisionsOf(rows: SourcingRow[], id = PROJECT_ID): SourcingDecisions {
  return { ...sourcingDecisions(1_000_000, rows), id }
}

function armorPlatesRow(overrides: Partial<SourcingRow> = {}): SourcingRow {
  return sourcingRow(COMPONENT_X.typeId, {
    typeName: COMPONENT_X.typeName,
    stage: 'component',
    activity: 'manufacturing',
    quantity: 40,
    runs: 4,
    options: {
      build: sourcingOption({ total: 1_200_000, perUnit: 30_000 }),
      jita: sourcingOption({ total: 1_500_000, perUnit: 37_500 }),
      structure: sourcingOption({ total: 1_350_000, perUnit: 33_750, availableQuantity: 5_000, structureId: '9001' }),
    },
    chosen: 'build',
    recommended: 'build',
    ...overrides,
  })
}

interface Outcomes {
  decisions?: SourcingDecisions | Error
  patch?: () => Promise<unknown>
}

function answerApi({ decisions = decisionsOf([armorPlatesRow()]), patch = () => Promise.resolve(projectOf()) }: Outcomes = {}): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (options?.method === 'PATCH') return patch()
    const resource = endpoint.split('?')[0].replace(`${PROJECT_PATH}/`, '')
    if (resource === 'sourcing-decisions') {
      if (decisions instanceof Error) throw decisions
      return decisions
    }
    if (resource === 'material-sourcing') return { id: PROJECT_ID, rows: [] }
    if (resource === 'contributions') return []
    if (resource === 'planning') return { id: PROJECT_ID, anchor: '2026-11-20T08:00:00+00:00', structureTimeBonusIncluded: false, plannedEnd: null, expectedEnd: null, delaySeconds: null, delayCause: null, lanesShown: true, characters: [], unscheduled: [] }
    if (resource === 'structure-health') return { id: PROJECT_ID, rows: [] }
    if (resource === 'sell-orders') return { projectId: PROJECT_ID, orders: [], charactersToReconnect: [], syncedAt: null }
    if (resource === 'sell-forecast') return { projectId: PROJECT_ID, sellStructure: null, products: [], totals: { structureNetRevenue: null, jitaNetRevenue: null, structureUnknownProductCount: 0, jitaUnknownProductCount: 0, remainingCost: null, remainingCostUnknownLineCount: 0, structureProfit: null, jitaProfit: null } }
    return undefined
  })
}

function patchBodies(): unknown[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(([endpoint, options]) => endpoint === PROJECT_PATH && options?.method === 'PATCH')
    .map(([, options]) => JSON.parse(String(options?.body)))
}

function decisionsReads(): number {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => String(endpoint).split('?')[0] === DECISIONS_PATH).length
}

interface DetailProps {
  card: FlowCard
  line: BomItem
  project?: GroupProject
  canSwitchSourcing?: boolean
}

async function mountDetail(props: DetailProps): Promise<VueWrapper> {
  setActivePinia(createPinia())
  const wrapper = mount(ProjectFlowCardDetail, {
    props: { projectId: PROJECT_ID, project: projectOf(), canLinkJobs: false, canRecordSales: false, canSwitchSourcing: true, ...props },
    global: {
      plugins: [i18n],
      stubs: { RouterLink: RouterLinkStub, JobLinkModal: true, teleport: true },
    },
  })
  await flushPromises()
  return wrapper
}

function builtArmorPlates(blacklistTypeIds: number[] = [TRITANIUM.typeId], canSwitchSourcing = true): Promise<VueWrapper> {
  return mountDetail({
    card: { ...flowCard('inProduction', { ...COMPONENT_X, quantity: 40, key: 'inProduction:11540:azbel' }), activity: 'manufacturing' },
    line: bomLineOf({ id: 'line-armor-plates', ...COMPONENT_X, isJob: true, activityType: 'manufacturing', runs: 4 }),
    project: projectOf({ blacklistTypeIds }),
    canSwitchSourcing,
  })
}

function boughtArmorPlates(blacklistTypeIds: number[] = [TRITANIUM.typeId, COMPONENT_X.typeId], canSwitchSourcing = true): Promise<VueWrapper> {
  return mountDetail({
    card: flowCard('toBuy', { ...COMPONENT_X, quantity: 80, key: 'toBuy:11540:jita' }),
    line: bomLineOf({ id: 'line-armor-plates', ...COMPONENT_X, requiredQuantity: 80, remainingQuantity: 80 }),
    project: projectOf({ blacklistTypeIds }),
    canSwitchSourcing,
  })
}

function part(wrapper: VueWrapper, name: string) {
  return wrapper.find(`[data-testid="flow-detail-buy-or-build-${name}"]`)
}

function textOf(wrapper: VueWrapper, name: string): string {
  const element = part(wrapper, name)
  return element.exists() ? squashed(element.text()) : `absent ${name}`
}

function optionRow(wrapper: VueWrapper, option: string): string {
  return textOf(wrapper, `option-${option}`)
}

async function press(wrapper: VueWrapper, name: string): Promise<void> {
  expect(part(wrapper, name).exists(), name).toBe(true)
  await part(wrapper, name).trigger('click')
}

function optionMarks(wrapper: VueWrapper): Record<string, { chosen: boolean; recommended: boolean }> {
  return Object.fromEntries(OPTIONS.map(option => {
    const row = part(wrapper, `option-${option}`)
    return [option, { chosen: row.exists() && row.attributes('data-chosen') === 'true', recommended: row.exists() && row.attributes('data-recommended') === 'true' }]
  }))
}

async function clickSwitchThenConfirm(wrapper: VueWrapper): Promise<void> {
  await press(wrapper, 'switch')
  await press(wrapper, 'switch-confirm')
  await flushPromises()
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  answerApi()
})

describe('ProjectFlowCardDetail buy or build block', () => {
  it('puts the buy or build block first on a built intermediate chosen and recommended on build, with a switch', async () => {
    const wrapper = await builtArmorPlates()

    expect(wrapper.findAll('section')[0].attributes('data-testid')).toBe('flow-detail-block-buyOrBuild')
    expect(squashed(wrapper.get('[data-testid="flow-detail-origin"]').text())).toBe(messageOf('en', 'groupIndustry.flow.detail.origins.buyOrBuild'))
    expect(optionMarks(wrapper)).toEqual({
      build: { chosen: true, recommended: true },
      jita: { chosen: false, recommended: false },
      structure: { chosen: false, recommended: false },
    })
    expect(optionRow(wrapper, 'build')).toContain(formatIsk(1_200_000))
    expect(optionRow(wrapper, 'build')).toContain(formatIsk(30_000))
    expect(optionRow(wrapper, 'jita')).toContain(formatIsk(1_500_000))
    expect(optionRow(wrapper, 'jita')).toContain(formatIsk(37_500))
    expect(optionRow(wrapper, 'structure')).toContain(formatIsk(1_350_000))
    expect(optionRow(wrapper, 'structure')).toContain(formatIsk(33_750))
    expect(part(wrapper, 'on-recommended').exists()).toBe(true)
    expect(part(wrapper, 'gap').exists()).toBe(false)
    expect(part(wrapper, 'excluded').exists()).toBe(false)
    expect(part(wrapper, 'switch').exists()).toBe(true)
  })

  it('shows a gap of + 300 000 ISK to the recommended option when the built option is dearer than buying at Jita', async () => {
    answerApi({
      decisions: decisionsOf([armorPlatesRow({
        options: {
          build: sourcingOption({ total: 1_200_000, perUnit: 30_000 }),
          jita: sourcingOption({ total: 900_000, perUnit: 22_500 }),
        },
        chosen: 'build',
        recommended: 'jita',
      })]),
    })

    const wrapper = await builtArmorPlates()

    expect(optionMarks(wrapper)).toEqual({
      build: { chosen: true, recommended: false },
      jita: { chosen: false, recommended: true },
      structure: { chosen: false, recommended: false },
    })
    expect(part(wrapper, 'option-structure').exists()).toBe(false)
    expect(textOf(wrapper, 'gap')).toContain(`+ ${formatIsk(300_000)}`)
    expect(part(wrapper, 'on-recommended').exists()).toBe(false)
  })

  it('shows the badge excluded and the choice at the capital market on an intermediate already bought, with a switch', async () => {
    answerApi({
      decisions: decisionsOf([armorPlatesRow({
        quantity: 80,
        options: {
          build: sourcingOption({ total: 2_900_000, perUnit: 36_250 }),
          jita: sourcingOption({ total: 2_640_000, perUnit: 33_000 }),
          structure: sourcingOption({ total: 2_480_000, perUnit: 31_000, availableQuantity: 5_000, structureId: '9001' }),
        },
        chosen: 'structure',
        recommended: 'structure',
        bought: true,
      })]),
    })

    const wrapper = await boughtArmorPlates()

    expect(part(wrapper, 'excluded').exists()).toBe(true)
    expect(optionMarks(wrapper)).toEqual({
      build: { chosen: false, recommended: false },
      jita: { chosen: false, recommended: false },
      structure: { chosen: true, recommended: true },
    })
    expect(optionRow(wrapper, 'structure')).toContain(formatIsk(2_480_000))
    expect(part(wrapper, 'switch').exists()).toBe(true)
  })

  it('says unknown, never 0, for an option whose total is null and for a gap that compares it', async () => {
    const unknownJita = sourcingOption({ total: null, perUnit: null, price: null })
    answerApi({
      decisions: decisionsOf([armorPlatesRow({
        options: { build: sourcingOption({ total: 1_200_000, perUnit: 30_000 }), jita: unknownJita },
        chosen: 'build',
        recommended: 'jita',
      })]),
    })

    const compared = await builtArmorPlates()

    expect(optionRow(compared, 'jita').toLowerCase()).toContain(UNKNOWN_LABEL)
    expect(optionRow(compared, 'jita')).not.toMatch(STANDALONE_ZERO)
    expect(textOf(compared, 'gap').toLowerCase()).toContain(UNKNOWN_LABEL)
    expect(textOf(compared, 'gap')).not.toContain('+')

    answerApi({
      decisions: decisionsOf([armorPlatesRow({
        options: { build: sourcingOption({ total: 1_200_000, perUnit: 30_000 }), jita: unknownJita },
        chosen: 'build',
        recommended: 'build',
      })]),
    })

    const notCompared = await builtArmorPlates()

    expect(optionRow(notCompared, 'jita').toLowerCase()).toContain(UNKNOWN_LABEL)
    expect(part(notCompared, 'gap').exists()).toBe(false)
    expect(part(notCompared, 'on-recommended').exists()).toBe(true)
  })

  it('flags the capital market option that has too little stock and shows the quantity on sale', async () => {
    answerApi({
      decisions: decisionsOf([armorPlatesRow({
        quantity: 6_000,
        options: {
          build: sourcingOption({ total: 1_200_000, perUnit: 30_000 }),
          structure: sourcingOption({ total: 1_050_000, perUnit: 26_250, eligible: false, availableQuantity: 4_321, structureId: '9001' }),
        },
        chosen: 'build',
        recommended: 'build',
      })]),
    })

    const wrapper = await builtArmorPlates()

    expect(optionRow(wrapper, 'structure')).toContain(formatNumber(4_321, 0))
    expect(optionRow(wrapper, 'build')).not.toContain(formatNumber(4_321, 0))
  })

  it('sends the blacklist with the intermediate added when switching to buy and confirming', async () => {
    const wrapper = await builtArmorPlates([TRITANIUM.typeId])

    await press(wrapper, 'switch')

    expect(part(wrapper, 'switch-confirmation').exists()).toBe(true)
    expect(patchBodies()).toEqual([])

    await press(wrapper, 'switch-confirm')
    await flushPromises()

    expect(patchBodies()).toEqual([{ blacklistTypeIds: [34, 11_540] }])
  })

  it('sends the blacklist with the intermediate removed when switching to build and confirming', async () => {
    const wrapper = await boughtArmorPlates([TRITANIUM.typeId, COMPONENT_X.typeId])

    await clickSwitchThenConfirm(wrapper)

    expect(patchBodies()).toEqual([{ blacklistTypeIds: [34] }])
  })

  it('sends nothing and keeps the block when the confirmation is cancelled', async () => {
    const wrapper = await builtArmorPlates()

    await press(wrapper, 'switch')
    await press(wrapper, 'switch-cancel')
    await flushPromises()

    expect(part(wrapper, 'switch-confirmation').exists()).toBe(false)
    expect(part(wrapper, 'switch').exists()).toBe(true)
    expect(patchBodies()).toEqual([])
    expect(decisionsReads()).toBe(1)
    expect(wrapper.emitted('switched')).toBeUndefined()
  })

  it('disables the confirmation buttons during the call, then reads the sourcing decisions again and emits switched', async () => {
    const pending = deferred<GroupProject>()
    answerApi({ patch: () => pending.promise })
    const wrapper = await builtArmorPlates()

    await press(wrapper, 'switch')
    await press(wrapper, 'switch-confirm')

    expect(part(wrapper, 'switch-confirm').exists() && part(wrapper, 'switch-confirm').attributes('disabled') !== undefined).toBe(true)
    expect(part(wrapper, 'switch-cancel').exists() && part(wrapper, 'switch-cancel').attributes('disabled') !== undefined).toBe(true)
    expect(wrapper.emitted('switched')).toBeUndefined()
    expect(decisionsReads()).toBe(1)

    pending.resolve(projectOf({ blacklistTypeIds: [34, 11_540] }))
    await flushPromises()

    expect(decisionsReads()).toBe(2)
    expect(wrapper.emitted('switched')).toHaveLength(1)
  })

  it('shows the translated reason of a server refusal, keeps the choice and the switch, and emits nothing', async () => {
    answerApi({ patch: () => Promise.reject(new Error(WORKED_LINE_REFUSAL)) })
    const wrapper = await builtArmorPlates()

    await clickSwitchThenConfirm(wrapper)

    expect(part(wrapper, 'switch-error').exists() ? part(wrapper, 'switch-error').attributes('role') : 'absent').toBe('alert')
    expect(textOf(wrapper, 'switch-error')).toContain(i18n.global.t('groupIndustry.refusals.workedLineDropped', { line: 'Capital Armor Plates' }))
    expect(textOf(wrapper, 'switch-error')).not.toContain(WORKED_LINE_REFUSAL)
    expect(part(wrapper, 'switch').exists()).toBe(true)
    expect(optionMarks(wrapper).build).toEqual({ chosen: true, recommended: true })
    expect(wrapper.emitted('switched')).toBeUndefined()
    expect(decisionsReads()).toBe(1)
  })

  it('shows the choice, the gap and the badge but no switch to a member without the right to switch', async () => {
    answerApi({
      decisions: decisionsOf([armorPlatesRow({
        quantity: 80,
        options: {
          build: sourcingOption({ total: 2_900_000, perUnit: 36_250 }),
          jita: sourcingOption({ total: 2_000_000, perUnit: 25_000 }),
          structure: sourcingOption({ total: 1_750_000, perUnit: 21_875, availableQuantity: 5_000, structureId: '9001' }),
        },
        chosen: 'jita',
        recommended: 'structure',
        bought: true,
      })]),
    })

    const wrapper = await boughtArmorPlates(undefined, false)

    expect(optionMarks(wrapper).jita.chosen).toBe(true)
    expect(textOf(wrapper, 'gap')).toContain(`+ ${formatIsk(250_000)}`)
    expect(part(wrapper, 'excluded').exists()).toBe(true)
    expect(part(wrapper, 'switch').exists()).toBe(false)
    expect(part(wrapper, 'switch-confirmation').exists()).toBe(false)
  })

  it('says there is no priced choice, with no switch, when the row is missing, of base stage or answered for another project', async () => {
    const baseRow = armorPlatesRow({
      stage: 'base',
      activity: null,
      runs: null,
      options: { jita: sourcingOption({ total: 80_000, perUnit: 800 }), structure: sourcingOption({ total: 90_000, perUnit: 900, structureId: '9001' }) },
    })
    const answers = [
      decisionsOf([sourcingRow(22, { typeName: 'Fernite Carbide' })]),
      decisionsOf([baseRow]),
      decisionsOf([armorPlatesRow()], 'project-other-batch'),
    ]

    for (const decisions of answers) {
      answerApi({ decisions })
      const wrapper = await builtArmorPlates()

      expect(part(wrapper, 'none').exists()).toBe(true)
      expect(OPTIONS.map(option => part(wrapper, `option-${option}`).exists())).toEqual([false, false, false])
      expect(part(wrapper, 'switch').exists()).toBe(false)
      expect(part(wrapper, 'gap').exists()).toBe(false)
    }
  })

  it('offers no buy or build block and reads no sourcing decisions on a base material card', async () => {
    const wrapper = await mountDetail({
      card: flowCard('toBuy', { ...TRITANIUM, quantity: 12_000, key: 'toBuy:34:jita' }),
      line: bomLineOf({ id: 'line-tritanium', ...TRITANIUM, requiredQuantity: 12_000, remainingQuantity: 12_000 }),
      project: projectOf({ blacklistTypeIds: [11_540] }),
    })

    expect(wrapper.find(BLOCK).exists()).toBe(false)
    expect(decisionsReads()).toBe(0)
  })

  it('offers no buy or build block and reads no sourcing decisions on the card of a final product', async () => {
    const wrapper = await mountDetail({
      card: { ...flowCard('inProduction', { ...ISHTAR, quantity: 12, key: 'inProduction:12005:azbel' }), activity: 'manufacturing' },
      line: bomLineOf({ id: 'line-ishtar', ...ISHTAR, isJob: true, activityType: 'manufacturing', runs: 12 }),
      project: projectOf({ items: [ISHTAR] }),
    })

    expect(wrapper.find(BLOCK).exists()).toBe(false)
    expect(decisionsReads()).toBe(0)
  })

  it('shows the error of the sourcing decisions in its own block and keeps the other blocks of the detail', async () => {
    answerApi({ decisions: new Error('The plan cannot be built') })

    const wrapper = await builtArmorPlates()

    expect(wrapper.find(`${BLOCK} [role="alert"]`).exists() ? wrapper.get(`${BLOCK} [role="alert"]`).text() : 'no alert in the block').toContain('The plan cannot be built')
    expect(part(wrapper, 'switch').exists()).toBe(false)
    expect(wrapper.find('[data-testid="flow-detail-block-planning"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="flow-detail-block-planning"] [role="alert"]').exists()).toBe(false)
  })
})
