import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MemberTable from '@/components/group-industry/MemberTable.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useAuthStore } from '@/stores/auth'
import type { GroupMember, GroupMemberRole } from '@/stores/group-industry/types'
import type { Character, User } from '@/types'

const OWNER_CHARACTER_ID = 2_112_000_001
const CONTRIBUTOR_CHARACTER_ID = 2_112_000_002
const NEWCOMER_CHARACTER_ID = 2_112_000_003

function member(
  id: string,
  name: string,
  characterId: number,
  role: GroupMemberRole,
  contributionCount: number,
): GroupMember {
  return {
    id,
    characterName: name,
    characterId,
    corporationId: 98_000_001,
    corporationName: 'Sabre Doctrine Corp',
    role,
    status: 'accepted',
    totalContributionValue: 0,
    contributionCount,
    joinedAt: '2026-10-20T18:30:00+00:00',
  }
}

function signedInAs(eveCharacterId: number, name: string): User {
  const mainCharacter: Character = {
    id: `character-${eveCharacterId}`,
    eveCharacterId,
    name,
    corporationId: 98_000_001,
    corporationName: 'Sabre Doctrine Corp',
    allianceId: null,
    allianceName: null,
    isMain: true,
    hasValidToken: true,
    lastSyncAt: null,
  }

  return {
    id: `user-${eveCharacterId}`,
    authStatus: 'full',
    mainCharacter,
    characters: [mainCharacter],
    corporationId: 98_000_001,
    corporationName: 'Sabre Doctrine Corp',
  }
}

function mountAs(eveCharacterId: number, name: string, locale: 'en' | 'fr' = 'en'): VueWrapper {
  setActivePinia(createPinia())
  const store = useGroupDistributionStore()
  store.members = [
    member('owner', 'Aria Solberg', OWNER_CHARACTER_ID, 'owner', 0),
    member('contributor', 'Brann Holt', CONTRIBUTOR_CHARACTER_ID, 'member', 3),
    member('newcomer', 'Cyra Lune', NEWCOMER_CHARACTER_ID, 'member', 0),
  ]
  vi.spyOn(store, 'fetchMembers').mockResolvedValue(undefined)
  useAuthStore().user = signedInAs(eveCharacterId, name)
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })

  return mount(MemberTable, {
    props: { projectId: 'project-sabre-batch', isAdmin: false, isOwner: false, ownerCorporationId: 98_000_001 },
    global: { plugins: [i18n], stubs: { ConfirmModal: true } },
  })
}

function leaveButtons(wrapper: VueWrapper) {
  return wrapper.findAll('button').filter(button => ['Leave', 'Quitter'].includes(button.text()))
}

describe('MemberTable leave button', () => {
  it('keeps the leave button visible but disabled for a member who contributed, with the reason', () => {
    const wrapper = mountAs(CONTRIBUTOR_CHARACTER_ID, 'Brann Holt')

    const buttons = leaveButtons(wrapper)
    expect(buttons).toHaveLength(1)
    expect(buttons[0].attributes('disabled')).toBeDefined()
    expect(buttons[0].attributes('title')).toBe(en.groupIndustry.members.leaveBlockedByContributions)
    expect(en.groupIndustry.members.leaveBlockedByContributions).not.toBe('')
  })

  it('writes the reason in French', () => {
    const wrapper = mountAs(CONTRIBUTOR_CHARACTER_ID, 'Brann Holt', 'fr')

    const buttons = leaveButtons(wrapper)
    expect(buttons).toHaveLength(1)
    expect(buttons[0].attributes('title')).toBe(fr.groupIndustry.members.leaveBlockedByContributions)
    expect(fr.groupIndustry.members.leaveBlockedByContributions).not.toBe('')
  })

  it('does not open the leave confirmation from the disabled button', async () => {
    const wrapper = mountAs(CONTRIBUTOR_CHARACTER_ID, 'Brann Holt')

    await leaveButtons(wrapper)[0].trigger('click')

    expect(wrapper.findComponent({ name: 'ConfirmModal' }).props('show')).toBe(false)
  })

  it('keeps the leave button enabled and without reason for a member who did not contribute', () => {
    const wrapper = mountAs(NEWCOMER_CHARACTER_ID, 'Cyra Lune')

    const buttons = leaveButtons(wrapper)
    expect(buttons).toHaveLength(1)
    expect(buttons[0].attributes('disabled')).toBeUndefined()
    expect(buttons[0].attributes('title')).toBeUndefined()
  })

  it('shows no leave button for the owner', () => {
    expect(leaveButtons(mountAs(OWNER_CHARACTER_ID, 'Aria Solberg'))).toHaveLength(0)
  })
})
