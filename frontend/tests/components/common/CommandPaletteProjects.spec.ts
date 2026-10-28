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
  industryProject,
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

  describe('labels', () => {
    it('lists a matching Industry project under its display name with its origin badge', async () => {
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('raven')

      expect(groupNames()).toEqual(['Projects'])
      expect(groupOptionTexts('Projects')).toEqual(['Raven batch Industry'])
    })

    it('lists a matching Group Industry project under its name with its origin badge', async () => {
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('titan')

      expect(groupNames()).toEqual(['Projects'])
      expect(groupOptionTexts('Projects')).toEqual(['Corp Titan Group Industry'])
    })

    it('labels an Industry project without a type name as an unknown name and marks it completed', async () => {
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('unknown')

      expect(groupOptionTexts('Projects')).toEqual(['Unknown name (#99000001) Industry Completed'])
    })

    it('labels an unnamed Group Industry project with one item by its runs and type', async () => {
      stubPaletteApi({
        groupIndustryProjects: [groupIndustryProject('g2', null, 'published', [groupProjectItem(22544, 'Hulk', 4)])],
      })
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('hulk')

      expect(groupOptionTexts('Projects')).toEqual(['4x Hulk Group Industry'])
    })

    it('labels an unnamed Group Industry project with several items by its item count', async () => {
      stubPaletteApi({
        groupIndustryProjects: [groupIndustryProject('g3', null, 'draft', [
          groupProjectItem(11567, 'Avatar', 2),
          groupProjectItem(23757, 'Archon', 6),
        ])],
      })
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('items')

      expect(groupOptionTexts('Projects')).toEqual(['2 items Group Industry'])
    })
  })

  describe('order and limit', () => {
    it('lists Industry then Group Industry projects, completed ones last', async () => {
      stubPaletteApi({
        industryProjects: [
          industryProject('f1', { displayName: 'Fleet Drake', productTypeId: 24698, productTypeName: 'Drake', runs: 6, status: 'completed' }),
          industryProject('f2', { displayName: 'Fleet Raven', productTypeId: 638, productTypeName: 'Raven', runs: 2, status: 'active' }),
        ],
        groupIndustryProjects: [
          groupIndustryProject('g4', 'Fleet Titan', 'completed', [groupProjectItem(11567, 'Avatar', 1)]),
          groupIndustryProject('g5', 'Fleet Hulk', 'in_progress', [groupProjectItem(22544, 'Hulk', 8)]),
        ],
      })
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('fleet')

      expect(groupOptionTexts('Projects')).toEqual([
        'Fleet Raven Industry',
        'Fleet Hulk Group Industry',
        'Fleet Drake Industry Completed',
        'Fleet Titan Group Industry Completed',
      ])
    })

    it('shows five projects at most', async () => {
      stubPaletteApi({
        industryProjects: [
          industryProject('d1', { displayName: 'Doctrine Ferox', productTypeId: 16227, productTypeName: 'Ferox', runs: 10 }),
          industryProject('d2', { displayName: 'Doctrine Drake', productTypeId: 24698, productTypeName: 'Drake', runs: 12 }),
          industryProject('d3', { displayName: 'Doctrine Osprey', productTypeId: 620, productTypeName: 'Osprey', runs: 20 }),
          industryProject('d4', { displayName: 'Doctrine Caracal', productTypeId: 621, productTypeName: 'Caracal', runs: 15 }),
        ],
        groupIndustryProjects: [
          groupIndustryProject('g6', 'Doctrine Scimitar', 'published', [groupProjectItem(11978, 'Scimitar', 9)]),
          groupIndustryProject('g7', 'Doctrine Basilisk', 'published', [groupProjectItem(11985, 'Basilisk', 7)]),
        ],
      })
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('doctrine')

      expect(groupOptionTexts('Projects')).toEqual([
        'Doctrine Ferox Industry',
        'Doctrine Drake Industry',
        'Doctrine Osprey Industry',
        'Doctrine Caracal Industry',
        'Doctrine Scimitar Group Industry',
      ])
    })

    it('places the projects group after the navigation group and reaches it with the arrows', async () => {
      stubPaletteApi({
        industryProjects: [industryProject('b1', { displayName: 'Batch of Ravens', productTypeId: 638, productTypeName: 'Raven', runs: 4 })],
        groupIndustryProjects: [],
      })
      const { router } = await mountOpenPalette()
      await typeQueryAndWaitForTypes('batch')
      expect(groupNames()).toEqual(['Navigation', 'Projects'])

      await pressArrowDown(1)
      expect(activeOptionText()).toBe('Batch of Ravens Industry')
      await pressKey('Enter')

      expect(router.currentRoute.value.fullPath).toBe('/industry?tab=projects&project=b1')
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
      stubPaletteApi({ industryProjects: [RAVEN_BATCH], groupIndustryProjects: [CORP_TITAN] })
      const { open, close } = await mountPalette()
      await open()
      await close()
      stubPaletteApi({
        industryProjects: [RAVEN_BATCH, industryProject('p3', { displayName: 'Rokh refit', productTypeId: 24688, productTypeName: 'Rokh', runs: 2 })],
        groupIndustryProjects: [CORP_TITAN],
      })

      await open()
      await typeQueryAndWaitForTypes('rokh')

      expect(groupOptionTexts('Projects')).toEqual(['Rokh refit Industry'])
    })

    it('shows a loading state while the projects are read', async () => {
      stubPaletteApi({ industryProjects: pendingAnswer(), groupIndustryProjects: pendingAnswer() })
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('raven')

      expect(groupText('Projects')).toContain('Loading...')
      expect(groupOptions('Projects')).toEqual([])
      expect(paletteText()).not.toContain('No results for')
    })
  })

  describe('unreadable sources', () => {
    it('reports the Industry projects as unavailable instead of an empty list', async () => {
      stubPaletteApi({ industryProjects: new Error('Internal Server Error') })
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('raven')

      expect(groupNames()).toEqual(['Projects'])
      expect(groupText('Projects')).toContain('Industry projects unavailable')
      expect(groupText('Projects')).not.toContain('Group Industry projects unavailable')
      expect(groupOptions('Projects')).toEqual([])
      expect(paletteText()).not.toContain('No results for')
    })

    it('reports the Group Industry projects as unavailable and still lists the Industry projects', async () => {
      stubPaletteApi({ groupIndustryProjects: new Error('Bad Gateway') })
      await mountOpenPalette()

      await typeQueryAndWaitForTypes('raven')

      expect(groupText('Projects')).toContain('Group Industry projects unavailable')
      expect(groupOptionTexts('Projects')).toEqual(['Raven batch Industry'])
    })

    it('keeps Enter inert on the unavailable notice', async () => {
      stubPaletteApi({ groupIndustryProjects: new Error('Bad Gateway') })
      const { wrapper, router } = await mountOpenPalette()
      await typeQueryAndWaitForTypes('titan')

      await pressKey('Enter')

      expect(router.currentRoute.value.fullPath).toBe(STARTING_ROUTE)
      expect(wrapper.emitted('close')).toBeUndefined()
    })
  })

  describe('hidden modules', () => {
    it('removes the Industry projects when industry is hidden', async () => {
      await mountOpenPalette({ hiddenModules: ['industry'] })

      await typeQueryAndWaitForTypes('raven')

      expect(groupNames()).not.toContain('Projects')
      expect(paletteText()).not.toContain('Raven batch')
    })

    it('removes the Group Industry projects when group-industry is hidden', async () => {
      await mountOpenPalette({ hiddenModules: ['group-industry'] })

      await typeQueryAndWaitForTypes('titan')

      expect(groupNames()).not.toContain('Projects')
      expect(paletteText()).not.toContain('Corp Titan')
    })

    it('keeps the Industry projects when only group-industry is hidden', async () => {
      await mountOpenPalette({ hiddenModules: ['group-industry'] })

      await typeQueryAndWaitForTypes('raven')

      expect(groupOptionTexts('Projects')).toEqual(['Raven batch Industry'])
    })
  })

  describe('execution', () => {
    it('opens the Industry project detail on Enter and closes', async () => {
      const { wrapper, router } = await mountOpenPalette()
      await typeQueryAndWaitForTypes('raven')

      await pressKey('Enter')

      expect(router.currentRoute.value.fullPath).toBe('/industry?tab=projects&project=p1')
      expect(wrapper.emitted('close')).toEqual([[]])
      expect(searchInput()).toBeNull()
    })

    it('opens the Group Industry project page on Enter', async () => {
      const { router } = await mountOpenPalette()
      await typeQueryAndWaitForTypes('titan')

      await pressKey('Enter')

      expect(router.currentRoute.value.fullPath).toBe('/group-industry/g1')
    })

    it('opens a clicked project', async () => {
      const { wrapper, router } = await mountOpenPalette()
      await typeQueryAndWaitForTypes('titan')

      groupOptions('Projects')[0].click()
      await flushPromises()

      expect(router.currentRoute.value.fullPath).toBe('/group-industry/g1')
      expect(wrapper.emitted('close')).toEqual([[]])
    })
  })
})
