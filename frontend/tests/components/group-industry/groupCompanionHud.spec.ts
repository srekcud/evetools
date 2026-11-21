import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import GroupCompanion from '@/components/group-industry/GroupCompanion.vue'
import type { CompanionTasks } from '@/components/group-industry/companionTasks'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { sellOrder } from '../../sellOrderFixtures'
import { bomLine, companionTasksOf, jobMatch } from './companionFixtures'

const NOW = Date.parse('2026-11-21T12:00:00+00:00')
const ONE_MINUTE = 60_000
const ONE_HOUR = 3_600_000
const LONG_NAME = 'Ishtar batch · Escadron des Confins Lointains de la Coalition Alpha Centauri Longue Traîne'
const PRODUCT = { typeId: 12_005, typeName: 'Ishtar', runs: 2, otherCount: 0 }

const writeText = vi.fn<(text: string) => Promise<void>>()

function mountCompanion(tasks: CompanionTasks, options: { size?: 'bar' | 'column'; locale?: 'en' | 'fr'; projectName?: string } = {}): VueWrapper {
  return mount(GroupCompanion, {
    props: { tasks, projectName: options.projectName ?? 'Ishtar batch', size: options.size ?? 'column', errorMessage: null },
    global: { plugins: [createI18n({ legacy: false, locale: options.locale ?? 'en', messages: { en, fr } })] },
  })
}

function normalized(wrapper: VueWrapper, testId: string): string | undefined {
  const element = wrapper.find(`[data-testid="${testId}"]`)
  return element.exists() ? element.text().replace(/[\s\u00a0\u202f]+/g, ' ') : undefined
}

function isHidden(wrapper: VueWrapper, testId: string): boolean {
  return (wrapper.get(`[data-testid="${testId}"]`).attributes('style') ?? '').includes('display: none')
}

function jobEntry(id: string, activityType: string, status: string, endDate: number | null, extra: Partial<Parameters<typeof jobMatch>[0]> = {}) {
  const item = bomLine({ id: `bom-${id}`, typeId: 12_000 + id.length, typeName: `Job ${id}`, isJob: true, activityType, runs: 4 })
  const match = jobMatch({ id: `match-${id}`, bomLineId: item.id, status, esiJobId: id.length, characterName: 'Alpha', endDate: endDate === null ? null : new Date(endDate).toISOString(), ...extra })
  return { match, item }
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  writeText.mockReset()
  writeText.mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
})

describe('GroupCompanion HUD header and progress', () => {
  it('shows the render of the product with its runs and the project name truncated with a tooltip, and no image without product', () => {
    const withProduct = mountCompanion(companionTasksOf({ product: PRODUCT }), { projectName: LONG_NAME })
    const withoutProduct = mountCompanion(companionTasksOf({ product: null }))

    expect({
      image: withProduct.get('[data-testid="companion-product-image"]').attributes('src'),
      runs: normalized(withProduct, 'companion-product-runs'),
      nameTitle: withProduct.get('[data-testid="companion-project-name"]').attributes('title'),
      nameTruncated: withProduct.get('[data-testid="companion-project-name"]').classes('truncate'),
      withoutProduct: withoutProduct.find('[data-testid="companion-product-image"]').exists(),
    }).toEqual({
      image: 'https://images.evetech.net/types/12005/render?size=64',
      runs: '×2',
      nameTitle: LONG_NAME,
      nameTruncated: true,
      withoutProduct: false,
    })
  })

  it('draws the circular BOM gauge, the final jobs running in the subline and one progress segment per activity, without a project section', () => {
    const wrapper = mountCompanion(companionTasksOf({
      bomPercent: 64,
      finalJobsRunning: 2,
      activityProgress: [
        { group: 'copy', delivered: 1, total: 1, percent: 100 },
        { group: 'material', delivered: 41, total: 50, percent: 82 },
      ],
    }))
    const overflowing = mountCompanion(companionTasksOf({ bomPercent: 140 }))

    expect({
      gauge: wrapper.get('[data-testid="companion-bom-gauge"]').attributes('data-percent'),
      percentText: normalized(wrapper, 'companion-bom-percent'),
      finalJobs: normalized(wrapper, 'companion-final-jobs'),
      segments: wrapper.findAll('[data-testid="companion-chain-segment"]').map(segment => [segment.attributes('data-group'), segment.attributes('data-percent')]),
      projectSection: wrapper.find('[data-testid="companion-project"]').exists(),
      overflowGauge: overflowing.get('[data-testid="companion-bom-gauge"]').attributes('data-percent'),
      overflowText: normalized(overflowing, 'companion-bom-percent'),
    }).toEqual({
      gauge: '64',
      percentText: '64%',
      finalJobs: 'Final jobs running: 2',
      segments: [['copy', '100'], ['material', '82']],
      projectSection: false,
      overflowGauge: '100',
      overflowText: '140%',
    })
  })
})

describe('GroupCompanion HUD tabs', () => {
  it('counts each tab, highlights the tabs holding an action to do and shows the tab that was clicked', async () => {
    const delivery = { item: bomLine({ id: 'bom-isogen', typeId: 37, typeName: 'Isogen' }), quantity: 5 }
    const ready = jobEntry('ishtar', 'manufacturing', 'ready', NOW - ONE_MINUTE)
    const wrapper = mountCompanion(companionTasksOf({
      free: [bomLine({ id: 'bom-nocxium', typeId: 38, typeName: 'Nocxium', freeQuantity: 3 })],
      mine: [delivery],
      awaitingApproval: [{ item: bomLine({ id: 'bom-morphite', typeId: 11_399, typeName: 'Morphite' }), quantity: 164 }],
      jobs: [ready],
      readyJobCount: 1,
    }))
    const quiet = mountCompanion(companionTasksOf({ jobs: [jobEntry('carbonide', 'reaction', 'active', NOW + ONE_HOUR)], readyJobCount: 0 }))
    const tabs = (target: VueWrapper) => ['free', 'mine', 'jobs'].map(key => [normalized(target, `companion-tab-${key}`), target.get(`[data-testid="companion-tab-${key}"]`).attributes('data-hot')])

    const before = { tabs: tabs(wrapper), freeHidden: isHidden(wrapper, 'companion-pane-free'), mineHidden: isHidden(wrapper, 'companion-pane-mine') }
    await wrapper.get('[data-testid="companion-tab-mine"]').trigger('click')
    const after = {
      freeHidden: isHidden(wrapper, 'companion-pane-free'),
      mineHidden: isHidden(wrapper, 'companion-pane-mine'),
      selected: wrapper.get('[data-testid="companion-tab-mine"]').attributes('aria-selected'),
      awaitingInMine: wrapper.findAll('[data-testid="companion-pane-mine"] [data-testid="companion-awaiting-line"]').length,
    }

    expect({ before, after, quiet: tabs(quiet) }).toEqual({
      before: {
        tabs: [['To assign 1', 'false'], ['To deliver 1', 'true'], ['My jobs 1', 'true']],
        freeHidden: false,
        mineHidden: true,
      },
      after: { freeHidden: true, mineHidden: false, selected: 'true', awaitingInMine: 1 },
      quiet: [['To assign 0', 'false'], ['To deliver 0', 'false'], ['My jobs 1', 'false']],
    })
  })
})

describe('GroupCompanion HUD lines', () => {
  it('frames a copy line, shows the type icon and writes the activity in the language of the player, unknown values as unknown', () => {
    const free = [
      bomLine({ id: 'bom-copy', typeId: 626, typeName: 'Vexor Blueprint', isJob: true, activityType: 'copy', runs: 4, freeQuantity: 1 }),
      bomLine({ id: 'bom-reaction', typeId: 16_670, typeName: 'Crystalline Carbonide', isJob: true, activityType: 'reaction', runs: 40, freeQuantity: 1 }),
      bomLine({ id: 'bom-ferrogel', typeId: 16_683, typeName: 'Ferrogel', isJob: true, activityType: 'reaction', runs: null, freeQuantity: 1 }),
    ]
    const english = mountCompanion(companionTasksOf({ free }))
    const french = mountCompanion(companionTasksOf({ free }), { locale: 'fr' })
    const lines = english.findAll('[data-testid="companion-free-line"]')

    expect({
      frames: lines.map(line => line.find('[data-testid="companion-copy-frame"]').exists()),
      icon: lines[0].get('[data-testid="companion-line-icon"]').attributes('src'),
      groups: lines.map(line => line.attributes('data-group')),
      english: lines.map(line => line.find('p:nth-of-type(2)').text()),
      french: french.findAll('[data-testid="companion-free-line"]').map(line => line.find('p:nth-of-type(2)').text()),
      unknownRuns: lines[2].find('[data-unknown="true"]').exists(),
    }).toEqual({
      frames: [true, false, false],
      icon: 'https://images.evetech.net/types/626/icon?size=64',
      groups: ['copy', 'reaction', 'reaction'],
      english: ['Copy · 4 runs', 'Reaction · 40 runs', expect.stringMatching(/^Reaction · /)],
      french: ['Copie · 4 runs', 'Réaction · 40 runs', expect.stringMatching(/^Réaction · /)],
      unknownRuns: true,
    })
  })

  it('shows the countdown of each job with its state, a full green bar for a ready job only and an unknown marker without end date', () => {
    const jobs = [
      jobEntry('invention', 'invention', 'paused', NOW + 50 * ONE_HOUR),
      jobEntry('carbonide', 'reaction', 'active', NOW + 90 * ONE_MINUTE),
      jobEntry('ishtar', 'manufacturing', 'ready', NOW - ONE_MINUTE),
      jobEntry('armor', 'manufacturing', 'active', null),
    ]

    const wrapper = mountCompanion(companionTasksOf({ jobs, readyJobCount: 1 }))
    const french = mountCompanion(companionTasksOf({ jobs: [jobs[0]] }), { locale: 'fr' })
    const lines = wrapper.findAll('[data-testid="companion-job"]')

    expect({
      states: lines.map(line => line.attributes('data-state')),
      eta: lines.map(line => line.get('[data-testid="companion-job-eta"]').text()),
      bars: lines.map(line => line.find('[data-testid="companion-job-bar"]').exists()),
      unknownEta: lines.map(line => line.get('[data-testid="companion-job-eta"]').attributes('data-unknown') ?? null),
      groupsOrder: wrapper.findAll('[data-testid^="companion-toggle-jobs-"]').map(toggle => toggle.attributes('data-testid')),
      pausedFr: french.get('[data-testid="companion-job-eta"]').text(),
    }).toEqual({
      states: ['paused', 'running', 'ready', 'running'],
      eta: ['2 d 02 h', '01:30', '✓', expect.not.stringMatching(/\d/)],
      bars: [false, false, true, false],
      unknownEta: [null, null, null, 'true'],
      groupsOrder: ['companion-toggle-jobs-invention', 'companion-toggle-jobs-reaction', 'companion-toggle-jobs-manufacturing'],
      pausedFr: '2 j 02 h',
    })
  })
})

describe('GroupCompanion HUD alerts', () => {
  it('delivers the line of a ready job from its alert and gives no action button to the alerts without action', async () => {
    const ready = jobEntry('ishtar', 'manufacturing', 'ready', NOW - ONE_MINUTE)
    const wrapper = mountCompanion(companionTasksOf({
      jobs: [ready],
      alerts: [{ key: `job_ready:${ready.match.id}`, kind: 'job_ready' }, { key: 'join_request', kind: 'join_request' }, { key: 'bom_complete', kind: 'bom_complete' }],
      joinRequests: 2,
    }))

    await wrapper.get('[data-testid="companion-alert-row"][data-kind="job_ready"] [data-testid="companion-alert-action"]').trigger('click')

    expect({
      deliver: wrapper.emitted('deliver'),
      actionButtons: wrapper.findAll('[data-testid="companion-alert-row"]').map(row => [row.attributes('data-kind'), row.find('[data-testid="companion-alert-action"]').exists()]),
      topAlert: wrapper.get('[data-testid="companion-alert"]').attributes('data-kind'),
    }).toEqual({
      deliver: [[ready.item.id]],
      actionButtons: [['job_ready', true], ['join_request', false], ['bom_complete', false]],
      topAlert: 'job_ready',
    })
  })

  it('copies the suggested price of the undercut order from its alert and offers no button without suggested price', async () => {
    const alerts = [{ key: 'undercut_sell_order', kind: 'undercut_sell_order' as const }]
    const copyable = mountCompanion(companionTasksOf({ alerts, undercutOrders: [sellOrder()] }))
    const withoutSuggestion = mountCompanion(companionTasksOf({ alerts, undercutOrders: [sellOrder({ suggestedPrice: null })] }))

    await copyable.get('[data-testid="companion-alert-action"]').trigger('click')
    await flushPromises()

    expect({
      written: writeText.mock.calls,
      confirmation: copyable.get('[data-testid="companion-alert-action"]').attributes('data-state'),
      withoutSuggestion: withoutSuggestion.find('[data-testid="companion-alert-action"]').exists(),
    }).toEqual({ written: [['689900']], confirmation: 'copied', withoutSuggestion: false })
  })

  it('reports a failed copy on the button instead of failing silently', async () => {
    writeText.mockRejectedValue(new Error('denied'))
    const wrapper = mountCompanion(companionTasksOf({ alerts: [{ key: 'undercut_sell_order', kind: 'undercut_sell_order' }], undercutOrders: [sellOrder()] }))

    await wrapper.get('[data-testid="companion-alert-action"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="companion-alert-action"]').attributes('data-state')).toBe('failed')
  })
})

describe('GroupCompanion HUD footer and bar', () => {
  it('shows the free value, the delivered lines over the total and the to do line for an admin only', () => {
    const admin = mountCompanion(companionTasksOf({ freeValue: { total: 48_200_000, unknownLines: 2 }, deliveredLines: 14, totalLines: 22, toReview: 3, joinRequests: 1 }))
    const plainMember = mountCompanion(companionTasksOf({ freeValue: { total: null, unknownLines: 1 }, deliveredLines: 0, totalLines: 6 }))

    expect({
      value: normalized(admin, 'companion-footer-free-value'),
      unknownSuffix: normalized(admin, 'companion-footer-free-unknown'),
      lines: normalized(admin, 'companion-footer-lines'),
      todo: normalized(admin, 'companion-todo'),
      memberValueUnknown: plainMember.get('[data-testid="companion-footer-free-value"]').attributes('data-unknown'),
      memberTodo: plainMember.find('[data-testid="companion-todo"]').exists(),
    }).toEqual({
      value: expect.stringContaining('48.2 M'),
      unknownSuffix: '+ 2 without price',
      lines: expect.stringContaining('14 / 22'),
      todo: expect.stringContaining('3 contributions to review'),
      memberValueUnknown: 'true',
      memberTodo: false,
    })
  })

  it('writes the bar with the segmented counters, the mini bar per activity, the ready jobs and the top alert with its action', async () => {
    const free = [
      bomLine({ id: 'bom-copy', typeId: 626, typeName: 'Vexor Blueprint', isJob: true, activityType: 'copy', runs: 4, freeQuantity: 1 }),
      ...[1, 2].map(index => bomLine({ id: `bom-reaction-${index}`, typeId: 16_000 + index, typeName: `Reaction ${index}`, isJob: true, activityType: 'reaction', runs: 2, freeQuantity: 1 })),
      ...[1, 2, 3].map(index => bomLine({ id: `bom-material-${index}`, typeId: 30 + index, typeName: `Mineral ${index}`, freeQuantity: 5 })),
    ]
    const ready = jobEntry('ishtar', 'manufacturing', 'ready', NOW - ONE_MINUTE)
    const tasks = companionTasksOf({
      product: PRODUCT,
      bomPercent: 64,
      free,
      mine: [{ item: bomLine({ id: 'bom-isogen', typeId: 37, typeName: 'Isogen' }), quantity: 5 }],
      jobs: [ready, jobEntry('armor', 'manufacturing', 'active', NOW + ONE_HOUR)],
      readyJobCount: 1,
      toReview: 3,
      alerts: [{ key: `job_ready:${ready.match.id}`, kind: 'job_ready' }],
    })

    const bar = mountCompanion(tasks, { size: 'bar' })
    const memberBar = mountCompanion(companionTasksOf({ toReview: null }), { size: 'bar' })
    await bar.get('[data-testid="companion-alert-action"]').trigger('click')

    expect({
      free: normalized(bar, 'companion-count-free'),
      miniBar: bar.findAll('[data-testid="companion-mini-segment"]').map(segment => [segment.attributes('data-group'), segment.attributes('data-count')]),
      mine: normalized(bar, 'companion-count-mine'),
      jobs: normalized(bar, 'companion-count-jobs'),
      ready: normalized(bar, 'companion-count-jobs-ready'),
      review: normalized(bar, 'companion-count-review'),
      memberReview: memberBar.find('[data-testid="companion-count-review"]').exists(),
      gauge: bar.get('[data-testid="companion-bom-gauge"]').attributes('data-percent'),
      image: bar.find('[data-testid="companion-product-image"]').exists(),
      tabs: bar.findAll('[data-testid^="companion-tab-"]').length,
      deliver: bar.emitted('deliver'),
    }).toEqual({
      free: expect.stringContaining('6'),
      miniBar: [['copy', '1'], ['reaction', '2'], ['material', '3']],
      mine: expect.stringContaining('1'),
      jobs: expect.stringContaining('2'),
      ready: '1 ✓',
      review: expect.stringContaining('3'),
      memberReview: false,
      gauge: '64',
      image: true,
      tabs: 0,
      deliver: [[ready.item.id]],
    })
  })
})
