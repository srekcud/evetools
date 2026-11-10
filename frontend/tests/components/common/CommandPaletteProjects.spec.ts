import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import {
  CORP_TITAN,
  RAVEN_BATCH,
  STARTING_ROUTE,
  activeOptionText,
  groupIndustryProject,
  groupNames,
  groupOptionTexts,
  groupOptions,
  groupProjectItem,
  groupText,
  mountOpenPalette,
  mountPalette,
  paletteText,
  pendingAnswer,
  pressArrowDown,
  pressKey,
  requestedEndpoints,
  searchInput,
  stubPaletteApi,
  typeQueryAndWaitForTypes,
} from './commandPaletteHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

describe('CommandPalette projects', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    stubPaletteApi()
  })

  afterEach(() => {
    vi.useRealTimers()
    document.body.innerHTML = ''
    localStorage.clear()
  })

  describe('single source', () => {
    it('reads the projects once from the unified list and opens each one on its project page', async () => {
      stubPaletteApi({
        projects: [
          groupIndustryProject('f1', 'Fleet Raven', 'in_progress', [groupProjectItem(638, 'Raven', 2)]),
          groupIndustryProject('f2', 'Fleet Titan', 'published', [groupProjectItem(11567, 'Avatar', 1)]),
        ],
      })
      const { router } = await mountOpenPalette()

      await typeQueryAndWaitForTypes('fleet')

      expect(requestedEndpoints().filter(endpoint => endpoint.includes('projects'))).toEqual(['/industry/projects'])
      expect(groupOptionTexts('Projects')).toEqual(['Fleet Raven', 'Fleet Titan'])
      groupOptions('Projects')[1].click()
      await flushPromises()
      expect(router.currentRoute.value.fullPath).toBe('/industry/projects/f2')
    })
  })

  describe('labels', () => {
    it('lists a matching project under its name', async () => {
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('raven')

      expect(groupNames()).toEqual(['Projects'])
      expect(groupOptionTexts('Projects')).toEqual(['Raven batch'])
    })

    it('labels an unnamed project with one item by its runs and type', async () => {
      stubPaletteApi({
        projects: [groupIndustryProject('g2', null, 'published', [groupProjectItem(22544, 'Hulk', 4)])],
      })
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('hulk')

      expect(groupOptionTexts('Projects')).toEqual(['4x Hulk'])
    })

    it('labels an unnamed project with several items by its item count', async () => {
      stubPaletteApi({
        projects: [groupIndustryProject('g3', null, 'draft', [
          groupProjectItem(11567, 'Avatar', 2),
          groupProjectItem(23757, 'Archon', 6),
        ])],
      })
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('items')

      expect(groupOptionTexts('Projects')).toEqual(['2 items'])
    })
  })

  describe('order and limit', () => {
    it('lists the projects with completed ones last', async () => {
      stubPaletteApi({
        projects: [
          groupIndustryProject('g4', 'Fleet Titan', 'completed', [groupProjectItem(11567, 'Avatar', 1)]),
          groupIndustryProject('g5', 'Fleet Hulk', 'in_progress', [groupProjectItem(22544, 'Hulk', 8)]),
          groupIndustryProject('g8', 'Fleet Drake', 'completed', [groupProjectItem(24698, 'Drake', 6)]),
          groupIndustryProject('g9', 'Fleet Raven', 'draft', [groupProjectItem(638, 'Raven', 2)]),
        ],
      })
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('fleet')

      expect(groupOptionTexts('Projects')).toEqual([
        'Fleet Hulk',
        'Fleet Raven',
        'Fleet Titan Completed',
        'Fleet Drake Completed',
      ])
    })

    it('shows five projects at most', async () => {
      stubPaletteApi({
        projects: [
          groupIndustryProject('d1', 'Doctrine Ferox', 'published', [groupProjectItem(16227, 'Ferox', 10)]),
          groupIndustryProject('d2', 'Doctrine Drake', 'published', [groupProjectItem(24698, 'Drake', 12)]),
          groupIndustryProject('d3', 'Doctrine Osprey', 'published', [groupProjectItem(620, 'Osprey', 20)]),
          groupIndustryProject('d4', 'Doctrine Caracal', 'published', [groupProjectItem(621, 'Caracal', 15)]),
          groupIndustryProject('g6', 'Doctrine Scimitar', 'published', [groupProjectItem(11978, 'Scimitar', 9)]),
          groupIndustryProject('g7', 'Doctrine Basilisk', 'published', [groupProjectItem(11985, 'Basilisk', 7)]),
        ],
      })
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('doctrine')

      expect(groupOptionTexts('Projects')).toEqual([
        'Doctrine Ferox',
        'Doctrine Drake',
        'Doctrine Osprey',
        'Doctrine Caracal',
        'Doctrine Scimitar',
      ])
    })

    it('places the projects group after the navigation group and reaches it with the arrows', async () => {
      stubPaletteApi({
        projects: [groupIndustryProject('b1', 'Batch of Ravens', 'in_progress', [groupProjectItem(638, 'Raven', 4)])],
      })
      const { router } = await mountOpenPalette()
      await typeQueryAndWaitForTypes('batch')
      expect(groupNames()).toEqual(['Navigation', 'Projects'])

      await pressArrowDown(1)
      expect(activeOptionText()).toBe('Batch of Ravens')
      await pressKey('Enter')

      expect(router.currentRoute.value.fullPath).toBe('/industry/projects/b1')
    })

    it('proposes no project for an empty query', async () => {
      await mountOpenPalette()

      expect(groupNames()).toEqual(['Navigation'])
      expect(paletteText()).not.toContain('Raven batch')
    })
  })

  describe('loading', () => {
    it('does not read the projects before the first opening', async () => {
      await mountPalette()

      expect(requestedEndpoints()).toEqual([])
    })

    it('reads the projects again at each opening', async () => {
      stubPaletteApi({ projects: [RAVEN_BATCH, CORP_TITAN] })
      const { open, close } = await mountPalette()
      await open()
      await close()
      stubPaletteApi({
        projects: [
          RAVEN_BATCH,
          groupIndustryProject('p3', 'Rokh refit', 'published', [groupProjectItem(24688, 'Rokh', 2)]),
        ],
      })

      await open()
      await typeQueryAndWaitForTypes('rokh')

      expect(groupOptionTexts('Projects')).toEqual(['Rokh refit'])
    })

    it('shows a loading state while the projects are read', async () => {
      stubPaletteApi({ projects: pendingAnswer() })
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('raven')

      expect(groupText('Projects')).toContain('Loading...')
      expect(groupOptions('Projects')).toEqual([])
      expect(paletteText()).not.toContain('No results for')
    })
  })

  describe('unreadable source', () => {
    it('reports the projects as unavailable instead of an empty list', async () => {
      stubPaletteApi({ projects: new Error('Internal Server Error') })
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('raven')

      expect(groupNames()).toEqual(['Projects'])
      expect(groupText('Projects')).toContain('Projects unavailable')
      expect(groupOptions('Projects')).toEqual([])
      expect(paletteText()).not.toContain('No results for')
    })

    it('keeps Enter inert on the unavailable notice', async () => {
      stubPaletteApi({ projects: new Error('Bad Gateway') })
      const { wrapper, router } = await mountOpenPalette()
      await typeQueryAndWaitForTypes('titan')

      await pressKey('Enter')

      expect(router.currentRoute.value.fullPath).toBe(STARTING_ROUTE)
      expect(wrapper.emitted('close')).toBeUndefined()
    })
  })

  describe('hidden modules', () => {
    it('removes the projects and does not read them when group-industry is hidden', async () => {
      await mountOpenPalette({ hiddenModules: ['group-industry'] })

      await typeQueryAndWaitForTypes('titan')

      expect(groupNames()).not.toContain('Projects')
      expect(paletteText()).not.toContain('Corp Titan')
      expect(requestedEndpoints()).not.toContain('/industry/projects')
    })

    it('removes the projects and does not read them when industry is hidden, since projects now belong to industry', async () => {
      await mountOpenPalette({ hiddenModules: ['industry'] })

      await typeQueryAndWaitForTypes('titan')

      expect(groupNames()).not.toContain('Projects')
      expect(paletteText()).not.toContain('Corp Titan')
      expect(requestedEndpoints()).not.toContain('/industry/projects')
    })

    it('keeps the projects when another module is hidden', async () => {
      await mountOpenPalette({ hiddenModules: ['market'] })

      await typeQueryAndWaitForTypes('titan')

      expect(groupOptionTexts('Projects')).toEqual(['Corp Titan'])
    })
  })

  describe('execution', () => {
    it('opens the project page on Enter and closes', async () => {
      const { wrapper, router } = await mountOpenPalette()
      await typeQueryAndWaitForTypes('raven')

      await pressKey('Enter')

      expect(router.currentRoute.value.fullPath).toBe('/industry/projects/p1')
      expect(wrapper.emitted('close')).toEqual([[]])
      expect(searchInput()).toBeNull()
    })

    it('opens a clicked project', async () => {
      const { wrapper, router } = await mountOpenPalette()
      await typeQueryAndWaitForTypes('titan')

      groupOptions('Projects')[0].click()
      await flushPromises()

      expect(router.currentRoute.value.fullPath).toBe('/industry/projects/g1')
      expect(wrapper.emitted('close')).toEqual([[]])
    })
  })
})
