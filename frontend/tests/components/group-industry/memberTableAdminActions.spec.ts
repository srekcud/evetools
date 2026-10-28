import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MemberTable from '@/components/group-industry/MemberTable.vue'
import { useAuthStore } from '@/stores/auth'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { GroupMember, GroupMemberRole } from '@/stores/group-industry/types'
import type { Character, User } from '@/types'

const OWNER_P = { id: 'member-p', name: 'Aria Solberg', characterId: 2112000001 }
const ADMIN_A = { id: 'member-a', name: 'Brann Holt', characterId: 2112000002 }
const ADMIN_B = { id: 'member-b', name: 'Cyra Lune', characterId: 2112000003 }
const MEMBER_M = { id: 'member-m', name: 'Dorn Achar', characterId: 2112000004 }

type Pilot = typeof OWNER_P

function member(pilot: Pilot, role: GroupMemberRole, contributionCount: number): GroupMember {
  return {
    id: pilot.id,
    characterName: pilot.name,
    characterId: pilot.characterId,
    corporationId: 98000001,
    corporationName: 'Sabre Doctrine Corp',
    role,
    status: 'accepted',
    totalContributionValue: contributionCount * 1_250_000,
    contributionCount,
    joinedAt: '2026-10-20T18:30:00+00:00',
  }
}

function userPlaying(pilot: Pilot): User {
  const mainCharacter: Character = {
    id: `character-${pilot.id}`,
    eveCharacterId: pilot.characterId,
    name: pilot.name,
    corporationId: 98000001,
    corporationName: 'Sabre Doctrine Corp',
    allianceId: null,
    allianceName: null,
    isMain: true,
    hasValidToken: true,
    lastSyncAt: null,
  }

  return {
    id: `user-${pilot.id}`,
    authStatus: 'full',
    mainCharacter,
    characters: [mainCharacter],
    corporationId: 98000001,
    corporationName: 'Sabre Doctrine Corp',
  }
}

function mountMemberTableAs(viewer: Pilot, access: { isAdmin: boolean, isOwner: boolean }): VueWrapper {
  setActivePinia(createPinia())
  useAuthStore().user = userPlaying(viewer)
  const store = useGroupDistributionStore()
  store.members = [
    member(OWNER_P, 'owner', 4),
    member(ADMIN_A, 'admin', 3),
    member(ADMIN_B, 'admin', 2),
    member(MEMBER_M, 'member', 1),
  ]
  vi.spyOn(store, 'fetchMembers').mockResolvedValue(undefined)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

  return mount(MemberTable, {
    props: { projectId: 'project-sabre-batch', ...access },
    global: { plugins: [i18n], stubs: { ConfirmModal: true } },
  })
}

function actionsByPilot(wrapper: VueWrapper): Record<string, string[]> {
  const actions: Record<string, string[]> = {}
  for (const row of wrapper.findAll('tbody tr')) {
    const pilot = row.find('td:first-child span.font-medium').text()
    actions[pilot] = row.findAll('td:last-child button').map(button => button.text())
  }

  return actions
}

describe('MemberTable actions offered according to the role of the viewer and of the row', () => {
  it('lets the owner promote a member, and demote or kick any admin', () => {
    const wrapper = mountMemberTableAs(OWNER_P, { isAdmin: true, isOwner: true })

    expect(actionsByPilot(wrapper)).toEqual({
      [OWNER_P.name]: [],
      [ADMIN_A.name]: ['Demote', 'Kick'],
      [ADMIN_B.name]: ['Demote', 'Kick'],
      [MEMBER_M.name]: ['Promote', 'Kick'],
    })
  })

  it('lets an admin only kick a member, without promoting him nor acting on another admin', () => {
    const wrapper = mountMemberTableAs(ADMIN_A, { isAdmin: true, isOwner: false })

    expect(actionsByPilot(wrapper)).toEqual({
      [OWNER_P.name]: [],
      [ADMIN_A.name]: [],
      [ADMIN_B.name]: [],
      [MEMBER_M.name]: ['Kick'],
    })
  })

  it('offers no management action to a plain member', () => {
    const wrapper = mountMemberTableAs(MEMBER_M, { isAdmin: false, isOwner: false })

    expect(actionsByPilot(wrapper)).toEqual({
      [OWNER_P.name]: [],
      [ADMIN_A.name]: [],
      [ADMIN_B.name]: [],
      [MEMBER_M.name]: [],
    })
  })
})
