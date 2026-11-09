import { mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ProjectKpiCards from '@/components/group-industry/ProjectKpiCards.vue'
import type { GroupProject } from '@/stores/group-industry/types'

function groupProject(overrides: Partial<GroupProject>): GroupProject {
  return {
    id: 'project',
    name: null,
    status: 'in_progress',
    shortLinkCode: 'link',
    containerName: null,
    ownerCharacterName: 'Aria Solberg',
    ownerCorporationId: 98000001,
    membersCount: 2,
    items: [],
    totalBomValue: 0,
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
    minimumOutsideSaving: 5,
    sellStructureId: null,
    sellStructureName: null,
    ...overrides,
  }
}

function pendingApprovalsValue(projects: GroupProject[]): string {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper: VueWrapper = mount(ProjectKpiCards, { props: { projects }, global: { plugins: [i18n] } })
  const card = wrapper.findAll('div.card-enter').find(candidate => candidate.find('p').text() === en.groupIndustry.kpis.pendingApprovals)
  if (card === undefined) throw new Error('Pending approvals card not found')

  return card.find('p.text-3xl').text()
}

describe('ProjectKpiCards pending approvals', () => {
  it('adds the pending contributions of the projects the viewer administers', () => {
    const value = pendingApprovalsValue([
      groupProject({ id: 'sabre', myRole: 'owner', pendingContributionCount: 3 }),
      groupProject({ id: 'jackdaw', myRole: 'admin', pendingContributionCount: 2 }),
    ])

    expect(value).toBe('5')
  })

  it('ignores the projects where the count is not exposed to the viewer', () => {
    const value = pendingApprovalsValue([
      groupProject({ id: 'sabre', myRole: 'owner', pendingContributionCount: 4 }),
      groupProject({ id: 'ishtar', myRole: 'member', pendingContributionCount: null }),
      groupProject({ id: 'caracal', myRole: null, myStatus: null, pendingContributionCount: null }),
    ])

    expect(value).toBe('4')
  })

  it('shows 0 when nothing awaits the review of the viewer', () => {
    const value = pendingApprovalsValue([
      groupProject({ id: 'sabre', myRole: 'owner', pendingContributionCount: 0 }),
      groupProject({ id: 'ishtar', myRole: 'member', pendingContributionCount: null }),
    ])

    expect(value).toBe('0')
  })
})
