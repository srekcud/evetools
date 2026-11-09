import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MemberTable from '@/components/group-industry/MemberTable.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { GroupMember, GroupMemberRole } from '@/stores/group-industry/types'

const OWNER_CORPORATION_ID = 98000001
const OTHER_CORPORATION_ID = 98000002

function member(id: string, name: string, role: GroupMemberRole, corporationId: number | null): GroupMember {
  return {
    id,
    characterName: name,
    characterId: 2112000000 + id.length,
    corporationId,
    corporationName: corporationId === null ? null : `Corp ${corporationId}`,
    role,
    status: 'accepted',
    totalContributionValue: 0,
    contributionCount: 0,
    joinedAt: '2026-10-20T18:30:00+00:00',
  }
}

function mountMemberTable(locale: 'en' | 'fr', ownerCorporationId: number | null): VueWrapper {
  setActivePinia(createPinia())
  const store = useGroupDistributionStore()
  store.members = [
    member('owner', 'Aria Solberg', 'owner', OWNER_CORPORATION_ID),
    member('mate', 'Brann Holt', 'member', OWNER_CORPORATION_ID),
    member('guest', 'Cyra Lune', 'admin', OTHER_CORPORATION_ID),
    member('stranger', 'Dorn Achar', 'member', null),
  ]
  vi.spyOn(store, 'fetchMembers').mockResolvedValue(undefined)
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })

  return mount(MemberTable, {
    props: { projectId: 'project-sabre-batch', isAdmin: false, isOwner: false, ownerCorporationId },
    global: { plugins: [i18n], stubs: { ConfirmModal: true } },
  })
}

function statusByPilot(wrapper: VueWrapper): Record<string, string> {
  const statuses: Record<string, string> = {}
  for (const row of wrapper.findAll('tbody tr')) {
    statuses[row.find('td:first-child span.font-medium').text()] = row.findAll('td')[3].text()
  }

  return statuses
}

describe('MemberTable status and entry route of a member', () => {
  it('tells how each member entered the project', () => {
    expect(statusByPilot(mountMemberTable('en', OWNER_CORPORATION_ID))).toEqual({
      'Aria Solberg': 'Active · creator',
      'Brann Holt': 'Active · same corporation',
      'Cyra Lune': 'Active · short link, approved',
      'Dorn Achar': 'Active',
    })
  })

  it('writes the status in French', () => {
    expect(statusByPilot(mountMemberTable('fr', OWNER_CORPORATION_ID))).toEqual({
      'Aria Solberg': 'Actif · créateur',
      'Brann Holt': 'Actif · même corporation',
      'Cyra Lune': 'Actif · lien court, approuvé',
      'Dorn Achar': 'Actif',
    })
  })

  it('does not guess the route of a member when the corporation of the owner is unknown', () => {
    expect(statusByPilot(mountMemberTable('en', null))).toEqual({
      'Aria Solberg': 'Active · creator',
      'Brann Holt': 'Active',
      'Cyra Lune': 'Active',
      'Dorn Achar': 'Active',
    })
  })
})
