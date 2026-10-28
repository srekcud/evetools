import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import type { SearchResult } from '@/stores/industry/types'
import {
  RAVEN,
  RAVEN_NAVY_ISSUE,
  TYPE_SEARCH_DEBOUNCE_MS,
  actionButtonTexts,
  activeOption,
  activeOptionText,
  commonTypesForQuery,
  groupNames,
  groupOptionTexts,
  groupOptions,
  groupText,
  mountOpenPalette,
  paletteText,
  pendingAnswer,
  pressArrowDown,
  pressKey,
  stubPaletteApi,
  typeQuery,
  typeQueryAndWaitForTypes,
  typeSearchQueries,
  waitForTypeSearch,
} from './commandPaletteHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const KEYSTROKE_GAP_MS = 100
const TYPE_RESULT_LIMIT = 8
const RAPIER: SearchResult = { typeId: 11963, typeName: 'Rapier', isT2: true }

const ANALYZE_MARGIN_ON_ENTER = 'Analyze margin Enter'
const BUY_VS_BUILD_ON_SHIFT_ENTER = 'Buy vs Build Shift+Enter'
const OPEN_IN_MARKET_ON_CTRL_ENTER = 'Open in Market Ctrl+Enter'

function manyRifterVariants(): SearchResult[] {
  return [
    { typeId: 587, typeName: 'Rifter', isT2: false },
    { typeId: 11371, typeName: 'Rifter Blueprint Copy', isT2: false },
    { typeId: 12034, typeName: 'Rifter Hound', isT2: true },
    { typeId: 12035, typeName: 'Rifter Jaguar', isT2: true },
    { typeId: 12036, typeName: 'Rifter Wolf', isT2: true },
    { typeId: 12037, typeName: 'Rifter Retribution', isT2: true },
    { typeId: 12038, typeName: 'Rifter Hawk', isT2: true },
    { typeId: 12039, typeName: 'Rifter Enyo', isT2: true },
    { typeId: 12040, typeName: 'Rifter Ishkur', isT2: true },
    { typeId: 12041, typeName: 'Rifter Harpy', isT2: true },
  ]
}

async function moveToRavenRow(): Promise<void> {
  await pressArrowDown(1)
  expect(activeOptionText()?.startsWith('Raven ')).toBe(true)
  expect(activeOptionText()?.startsWith('Raven Navy Issue')).toBe(false)
}

enableAutoUnmount(afterEach)

describe('CommandPalette types', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    stubPaletteApi()
  })

  afterEach(() => {
    vi.useRealTimers()
    document.body.innerHTML = ''
    localStorage.clear()
  })

  describe('search request', () => {
    it('sends a single search for the last query, 250 ms after the last keystroke', async () => {
      await mountOpenPalette()

      await typeQuery('r')
      await vi.advanceTimersByTimeAsync(KEYSTROKE_GAP_MS)
      await typeQuery('ra')
      await vi.advanceTimersByTimeAsync(KEYSTROKE_GAP_MS)
      await typeQuery('rav')
      await vi.advanceTimersByTimeAsync(TYPE_SEARCH_DEBOUNCE_MS - 1)
      expect(typeSearchQueries()).toEqual([])

      await vi.advanceTimersByTimeAsync(1)
      await flushPromises()

      expect(typeSearchQueries()).toEqual(['rav'])
    })

    it('sends no search under two characters', async () => {
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('r')
      await vi.advanceTimersByTimeAsync(TYPE_SEARCH_DEBOUNCE_MS * 4)

      expect(typeSearchQueries()).toEqual([])
    })

    it('ignores the answer of an outdated query', async () => {
      let answerOutdatedQuery: (types: SearchResult[]) => void = () => {}
      stubPaletteApi({
        typesForQuery: query => query === 'ra'
          ? new Promise<SearchResult[]>(resolve => { answerOutdatedQuery = resolve })
          : commonTypesForQuery(query),
      })
      await mountOpenPalette()
      await typeQueryAndWaitForTypes('ra')
      await typeQueryAndWaitForTypes('rav')

      answerOutdatedQuery([RAPIER])
      await flushPromises()

      expect(typeSearchQueries()).toEqual(['ra', 'rav'])
      expect(groupOptionTexts('Types')).toEqual(['Raven', 'Raven Navy Issue'])
      expect(paletteText()).not.toContain('Rapier')
    })
  })

  describe('rows', () => {
    it('lists the types after the projects, in the order of the API', async () => {
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('rav')

      expect(groupNames()).toEqual(['Projects', 'Types'])
      expect(groupOptionTexts('Projects')).toEqual(['Raven batch Industry'])
      expect(groupOptionTexts('Types')).toEqual(['Raven', 'Raven Navy Issue'])
    })

    it('shows eight types at most', async () => {
      stubPaletteApi({ typesForQuery: query => query === 'rifter' ? manyRifterVariants() : [] })
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('rifter')

      expect(groupNames()).toEqual(['Types'])
      expect(groupOptions('Types')).toHaveLength(TYPE_RESULT_LIMIT)
      expect(activeOptionText()?.startsWith('Rifter ')).toBe(true)
      expect(groupOptionTexts('Types').slice(1)).toEqual([
        'Rifter Blueprint Copy',
        'Rifter Hound',
        'Rifter Jaguar',
        'Rifter Wolf',
        'Rifter Retribution',
        'Rifter Hawk',
        'Rifter Enyo',
      ])
    })

    it('keeps the active row when the types arrive', async () => {
      await mountOpenPalette()
      await typeQuery('rav')
      expect(activeOptionText()).toBe('Raven batch Industry')

      await waitForTypeSearch()

      expect(groupOptionTexts('Types')).toEqual(['Raven', 'Raven Navy Issue'])
      expect(activeOptionText()).toBe('Raven batch Industry')
    })

    it('shows no types group for an empty answer', async () => {
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('raven')

      expect(groupNames()).toEqual(['Projects'])
      expect(paletteText()).not.toContain('Type search unavailable')
    })

    it('reports no result when the type search answers nothing and nothing else matches', async () => {
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('zzz')

      expect(groupNames()).toEqual([])
      expect(paletteText()).toContain('No results for "zzz"')
    })
  })

  describe('unknown types', () => {
    it('shows a searching state while the search is pending', async () => {
      stubPaletteApi({ typesForQuery: () => pendingAnswer() })
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('rav')

      expect(groupText('Types')).toContain('Searching...')
      expect(groupOptions('Types')).toEqual([])
    })

    it('reports the type search as unavailable instead of an empty list', async () => {
      stubPaletteApi({ typesForQuery: () => new Error('Service Unavailable') })
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('rav')

      expect(groupNames()).toEqual(['Projects', 'Types'])
      expect(groupText('Types')).toContain('Type search unavailable')
      expect(groupOptions('Types')).toEqual([])
      expect(groupOptionTexts('Projects')).toEqual(['Raven batch Industry'])
      expect(paletteText()).not.toContain('No results for')
    })
  })

  describe('actions', () => {
    it('shows the three actions with their keys on the active type row only', async () => {
      await mountOpenPalette()
      await typeQueryAndWaitForTypes('rav')

      await moveToRavenRow()

      expect(actionButtonTexts(activeOption() as HTMLElement)).toEqual([
        ANALYZE_MARGIN_ON_ENTER,
        BUY_VS_BUILD_ON_SHIFT_ENTER,
        OPEN_IN_MARKET_ON_CTRL_ENTER,
      ])
      expect(actionButtonTexts(groupOptions('Types')[1])).toEqual([])
    })

    it('analyzes the margin of the active type on Enter and closes', async () => {
      const { wrapper, router } = await mountOpenPalette()
      await typeQueryAndWaitForTypes('rav')
      await moveToRavenRow()

      await pressKey('Enter')

      expect(router.currentRoute.value.fullPath).toBe('/industry?tab=margins&typeId=638')
      expect(wrapper.emitted('close')).toEqual([[]])
    })

    it('opens Buy vs Build for the active type on Shift+Enter', async () => {
      const { router } = await mountOpenPalette()
      await typeQueryAndWaitForTypes('rav')
      await moveToRavenRow()

      await pressKey('Enter', { shiftKey: true })

      expect(router.currentRoute.value.fullPath).toBe('/industry?tab=buy-vs-build&typeId=638')
    })

    it('opens the active type in Market on Ctrl+Enter', async () => {
      const { router } = await mountOpenPalette()
      await typeQueryAndWaitForTypes('rav')
      await moveToRavenRow()

      await pressKey('Enter', { ctrlKey: true })

      expect(router.currentRoute.value.fullPath).toBe('/market?tab=detail&typeId=638')
    })

    it('opens the active type in Market on Cmd+Enter', async () => {
      const { router } = await mountOpenPalette()
      await typeQueryAndWaitForTypes('rav')
      await pressArrowDown(2)

      await pressKey('Enter', { metaKey: true })

      expect(router.currentRoute.value.fullPath).toBe(`/market?tab=detail&typeId=${RAVEN_NAVY_ISSUE.typeId}`)
    })

    it('analyzes the margin of a clicked type row', async () => {
      const { router } = await mountOpenPalette()
      await typeQueryAndWaitForTypes('rav')

      groupOptions('Types')[1].click()
      await flushPromises()

      expect(router.currentRoute.value.fullPath).toBe(`/industry?tab=margins&typeId=${RAVEN_NAVY_ISSUE.typeId}`)
    })

    it('runs the clicked action of the active type row', async () => {
      const { wrapper, router } = await mountOpenPalette()
      await typeQueryAndWaitForTypes('rav')
      await moveToRavenRow()
      const marketAction = Array.from((activeOption() as HTMLElement).querySelectorAll('button'))[2]

      marketAction.click()
      await flushPromises()

      expect(router.currentRoute.value.fullPath).toBe(`/market?tab=detail&typeId=${RAVEN.typeId}`)
      expect(wrapper.emitted('close')).toEqual([[]])
    })
  })

  describe('hidden modules', () => {
    it('keeps searching for the Market action when industry is hidden', async () => {
      await mountOpenPalette({ hiddenModules: ['industry'] })

      await typeQueryAndWaitForTypes('raven')

      expect(typeSearchQueries()).toEqual(['raven'])
      expect(groupNames()).toEqual([])
    })

    it('moves the Market action to Enter when industry is hidden', async () => {
      const { router } = await mountOpenPalette({ hiddenModules: ['industry'] })
      await typeQueryAndWaitForTypes('rav')
      expect(activeOptionText()?.startsWith('Raven ')).toBe(true)

      expect(actionButtonTexts(activeOption() as HTMLElement)).toEqual(['Open in Market Enter'])
      await pressKey('Enter')

      expect(router.currentRoute.value.fullPath).toBe(`/market?tab=detail&typeId=${RAVEN.typeId}`)
    })

    it('removes the Market action when market is hidden', async () => {
      await mountOpenPalette({ hiddenModules: ['market'] })
      await typeQueryAndWaitForTypes('rav')

      await moveToRavenRow()

      expect(actionButtonTexts(activeOption() as HTMLElement)).toEqual([
        ANALYZE_MARGIN_ON_ENTER,
        BUY_VS_BUILD_ON_SHIFT_ENTER,
      ])
    })

    it('neither searches nor shows types when industry and market are hidden', async () => {
      await mountOpenPalette({ hiddenModules: ['industry', 'market'] })

      await typeQueryAndWaitForTypes('rav')

      expect(typeSearchQueries()).toEqual([])
      expect(groupNames()).not.toContain('Types')
    })
  })
})
