import { mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectKpiCards from '@/components/group-industry/ProjectKpiCards.vue'
import type { GroupProject, GroupProjectStatus } from '@/stores/group-industry/types'

function groupProject(status: GroupProjectStatus, totalBomValue: number | null = 0): GroupProject {
  return {
    id: `project-${status}-${totalBomValue}`,
    name: null,
    status,
    shortLinkCode: 'link',
    containerNames: [],
    ownerCharacterName: 'Aria Solberg',
    ownerCorporationId: 98000001,
    membersCount: 2,
    contributionsCount: 0,
    items: [],
    totalBomValue,
    fulfillmentPercent: 0,
    approvedContributionValue: 0,
    brokerFeePercent: 3.6,
    salesTaxPercent: 3.6,
    lineRentalRatesOverride: null,
    blacklistGroupIds: [],
    blacklistTypeIds: [],
    createdAt: '2026-10-20T18:30:00+00:00',
    myRole: 'member',
    myStatus: 'accepted',
    private: false,
    notes: null,
    acceptsOutsideFavoriteSystem: false,
    excludesInvention: false,
    minimumOutsideSaving: 5,
    sellStructureId: null,
    sellStructureName: null,
  }
}

const mounted: VueWrapper[] = []

function mountKpiCards(projects: GroupProject[], locale: 'en' | 'fr' = 'en'): VueWrapper {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ProjectKpiCards, { props: { projects }, global: { plugins: [i18n] } })
  mounted.push(wrapper)
  return wrapper
}

function kpiCard(wrapper: VueWrapper, label: string): DOMWrapper<Element> {
  const card = wrapper.findAll('div.card-enter').find(candidate => candidate.find('p').text() === label)
  if (card === undefined) throw new Error(`KPI card ${label} not found`)
  return card
}

function kpiValue(wrapper: VueWrapper, label: string): string {
  return kpiCard(wrapper, label).find('p.text-3xl').text()
}

afterEach(() => {
  mounted.splice(0).forEach(wrapper => wrapper.unmount())
})

describe('ProjectKpiCards active projects', () => {
  it('counts only the projects in production', () => {
    const wrapper = mountKpiCards([
      groupProject('draft'),
      groupProject('published'),
      groupProject('in_progress'),
      groupProject('in_progress', 5),
      groupProject('selling'),
      groupProject('completed'),
    ])

    expect(kpiValue(wrapper, en.groupIndustry.kpis.activeProjects)).toBe('2')
  })

  it('describes the count as projects in production', () => {
    const wrapper = mountKpiCards([groupProject('in_progress')])

    expect(kpiCard(wrapper, en.groupIndustry.kpis.activeProjects).text()).toContain('in production')
  })

  it('describes the count as projects in production in French', () => {
    const wrapper = mountKpiCards([groupProject('in_progress')], 'fr')

    expect(kpiCard(wrapper, fr.groupIndustry.kpis.activeProjects).text()).toContain('en production')
  })
})

describe('ProjectKpiCards pipeline value', () => {
  it('leaves the completed projects out of the pipeline', () => {
    const wrapper = mountKpiCards([
      groupProject('in_progress', 3_000_000),
      groupProject('completed', 9_000_000),
      groupProject('selling', 1_000_000),
    ])

    expect(kpiValue(wrapper, en.groupIndustry.kpis.pipelineValue)).toBe('4.0 M')
  })

  it('does not report a completed project with an unknown value as excluded', () => {
    const wrapper = mountKpiCards([groupProject('in_progress', 3_000_000), groupProject('completed', null)])

    expect(kpiCard(wrapper, en.groupIndustry.kpis.pipelineValue).text()).not.toContain('excluded')
  })
})

describe('ProjectKpiCards selling projects', () => {
  it('counts the projects awaiting sales or closing', () => {
    const wrapper = mountKpiCards([
      groupProject('in_progress'),
      groupProject('selling'),
      groupProject('selling', 2),
      groupProject('completed'),
    ])

    expect(kpiValue(wrapper, en.groupIndustry.kpis.sellingProjects)).toBe('2')
  })

  it('shows 0 when no project is selling', () => {
    const wrapper = mountKpiCards([groupProject('in_progress')])

    expect(kpiValue(wrapper, en.groupIndustry.kpis.sellingProjects)).toBe('0')
  })

  it('labels the card in French', () => {
    const wrapper = mountKpiCards([groupProject('selling')], 'fr')

    expect(kpiValue(wrapper, fr.groupIndustry.kpis.sellingProjects)).toBe('1')
  })
})
