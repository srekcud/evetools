import { DOMWrapper, enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { industryAction } from '../industryActionFixtures'
import { INDUSTRY_ACTIONS_URL, mountMainLayoutAt, setViewportWidth } from './mainLayoutHarness'

const PHONE_WIDTH = 375
const COUNT_SELECTOR = '[data-testid="nav-industry-action-count"]'
const MENU_BUTTON_SELECTOR = 'header button[aria-controls]'
const NYX_PROJECT_ID = '0b6f3c2e-8d41-4a7b-9e15-5c2d7f80a3a1'
const WIDOW_PROJECT_ID = '7e2a9d14-3c5b-4f86-a0d2-91b4c6e8f2b7'
const HECATE_PROJECT_ID = '5d3c8b21-6a47-4e90-b1f2-8c9d0e7a4b63'

const THREE_ACTIONS = {
  status: 200,
  body: [
    industryAction({ projectId: NYX_PROJECT_ID, code: 'source_material', blocked: true, tab: 'materials', typeId: 34, typeName: 'Tritanium', quantity: 147 }),
    industryAction({ projectId: WIDOW_PROJECT_ID, code: 'record_sale', tab: 'sales', quantity: 3 }),
    industryAction({ projectId: HECATE_PROJECT_ID, code: 'start_project', targetStatus: 'in_progress' }),
  ],
}

function visibleCounts(): string[] {
  return Array.from(document.querySelectorAll(COUNT_SELECTOR))
    .filter(count => new DOMWrapper(count).isVisible())
    .map(count => count.textContent?.trim() ?? '')
}

function actionRequestCount(): number {
  return vi.mocked(fetch).mock.calls.filter(([input]) => String(input) === INDUSTRY_ACTIONS_URL).length
}

enableAutoUnmount(afterEach)

describe('MainLayout industry action count', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows the number of actions on the industry entry of the sidebar and of the mobile drawer only', async () => {
    const { wrapper: wideWrapper } = await mountMainLayoutAt('/dashboard', { industryActionsAnswer: THREE_ACTIONS })
    const wideCounts = visibleCounts()
    const industryLinkOfWideCount = document.querySelector(COUNT_SELECTOR)?.closest('a')?.getAttribute('href')
    wideWrapper.unmount()

    await mountMainLayoutAt('/dashboard', { viewportWidth: PHONE_WIDTH, industryActionsAnswer: THREE_ACTIONS })
    const phoneCountsWithDrawerClosed = visibleCounts()
    setViewportWidth(PHONE_WIDTH)
    document.querySelector<HTMLButtonElement>(MENU_BUTTON_SELECTOR)?.click()
    await flushPromises()

    expect({ wideCounts, industryLinkOfWideCount, phoneCountsWithDrawerClosed, drawerCounts: visibleCounts() }).toEqual({
      wideCounts: ['3'],
      industryLinkOfWideCount: '/industry',
      phoneCountsWithDrawerClosed: [],
      drawerCounts: ['3'],
    })
  })

  it('shows no count when the queue is empty and none when the queue cannot be loaded', async () => {
    const { wrapper: emptyQueueWrapper } = await mountMainLayoutAt('/dashboard', { industryActionsAnswer: { status: 200, body: [] } })
    const countsOfEmptyQueue = visibleCounts()
    emptyQueueWrapper.unmount()

    await mountMainLayoutAt('/dashboard', { industryActionsAnswer: { status: 500, body: { detail: 'Actions service unavailable' } } })

    expect({ countsOfEmptyQueue, countsOfFailedQueue: visibleCounts() }).toEqual({ countsOfEmptyQueue: [], countsOfFailedQueue: [] })
  })

  it('loads the queue once when the industry module is shown and not at all when it is hidden', async () => {
    const { wrapper: shownWrapper } = await mountMainLayoutAt('/dashboard', { industryActionsAnswer: THREE_ACTIONS })
    const requestsWithModuleShown = actionRequestCount()
    shownWrapper.unmount()

    await mountMainLayoutAt('/dashboard', { storedHiddenModules: ['industry'], industryActionsAnswer: THREE_ACTIONS })

    expect({ requestsWithModuleShown, requestsWithModuleHidden: actionRequestCount() }).toEqual({ requestsWithModuleShown: 1, requestsWithModuleHidden: 0 })
  })
})
