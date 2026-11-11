import { enableAutoUnmount, mount, RouterLinkStub } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import CorporationAccessRequired from '@/components/ledger/CorporationAccessRequired.vue'
import type { CorporationRoleAccess } from '@/composables/useCorporationRoleAccess'

enableAutoUnmount(afterEach)

const MAIN_ID = 2112000001
const ALT_ID = 2112000002

const ROLE_MISSING: CorporationRoleAccess = {
  corporationId: 98000001,
  status: 'role_missing',
  requiredRole: 'Accountant',
  characters: [
    { characterId: MAIN_ID, name: 'Ilyra Voss', isMain: true, state: 'without_role', roles: ['Auditor', 'Junior_Accountant'] },
    { characterId: ALT_ID, name: 'Aurel Kade', isMain: false, state: 'without_role', roles: [] },
  ],
}

const RECONNECT: CorporationRoleAccess = {
  corporationId: 98000001,
  status: 'reconnect',
  requiredRole: 'Station_Manager',
  characters: [{ characterId: MAIN_ID, name: 'Ilyra Voss', isMain: true, state: 'reconnect', roles: null }],
}

const ESI_ERROR: CorporationRoleAccess = {
  corporationId: 98000001,
  status: 'esi_error',
  requiredRole: 'Accountant',
  characters: [{ characterId: MAIN_ID, name: 'Ilyra Voss', isMain: true, state: 'unreadable', roles: null }],
}

function mountCard(access: CorporationRoleAccess, isRechecking = false, locale: 'en' | 'fr' = 'en') {
  const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { en, fr } })
  return mount(CorporationAccessRequired, {
    props: { access, isRechecking },
    global: { plugins: [i18n], stubs: { RouterLink: RouterLinkStub } },
  })
}

describe('CorporationAccessRequired for a missing role', () => {
  it('states the required role and who was checked, as a status and never as an alert', () => {
    const wrapper = mountCard(ROLE_MISSING)

    const card = wrapper.get('[data-access-required]')
    expect(card.attributes('data-access-state')).toBe('role_missing')
    expect(card.text()).toContain('Accountant')
    expect(card.text()).toContain('Director')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-access-character]').map(character => character.attributes('data-character-id'))).toEqual([String(MAIN_ID), String(ALT_ID)])
  })

  it('shows a compact portrait and a without-role mark for each checked character', () => {
    const wrapper = mountCard(ROLE_MISSING)

    const main = wrapper.get(`[data-access-character][data-character-id="${MAIN_ID}"]`)
    expect(main.get('img').attributes('src')).toContain(`/characters/${MAIN_ID}/portrait`)
    expect(main.text()).toContain('Ilyra Voss')
    expect(main.find('[data-without-role]').exists()).toBe(true)
    expect(wrapper.findAll('[data-without-role]')).toHaveLength(2)
  })

  it('keeps the full role lists inside a collapsed detail', () => {
    const wrapper = mountCard(ROLE_MISSING)

    const detail = wrapper.get('details[data-access-roles]')
    expect(detail.attributes('open')).toBeUndefined()
    expect(detail.text()).toContain('Junior_Accountant')
    expect(detail.text()).toContain('Auditor')
    expect(wrapper.get('[data-access-required] > :not(details)').text()).not.toContain('Junior_Accountant')
  })

  it('says what to do and offers a recheck that emits', async () => {
    const wrapper = mountCard(ROLE_MISSING)

    expect(wrapper.get('[data-access-steps]').text()).toContain('director')
    expect(wrapper.get('[data-access-steps]').text()).toContain('1 h')
    await wrapper.get('[data-recheck]').trigger('click')

    expect(wrapper.emitted('recheck')).toHaveLength(1)
  })

  it('disables the recheck while it runs', () => {
    const wrapper = mountCard(ROLE_MISSING, true)

    expect(wrapper.get('[data-recheck]').attributes('disabled')).toBeDefined()
  })

  it('is written in French for a French user', () => {
    const wrapper = mountCard(ROLE_MISSING, false, 'fr')

    expect(wrapper.get('[data-access-required]').text()).toContain('Accountant')
    expect(wrapper.get('[data-recheck]').text()).toBe('Revérifier')
  })
})

describe('CorporationAccessRequired for a reconnection', () => {
  it('links to the characters page instead of asking for a role', () => {
    const wrapper = mountCard(RECONNECT)

    expect(wrapper.get('[data-access-required]').attributes('data-access-state')).toBe('reconnect')
    expect(wrapper.getComponent(RouterLinkStub).props('to')).toEqual({ name: 'characters' })
    expect(wrapper.find('[data-reconnect-link]').exists()).toBe(true)
    expect(wrapper.find('[data-access-steps]').exists()).toBe(false)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('marks the character to reconnect rather than without the role', () => {
    const wrapper = mountCard(RECONNECT)

    expect(wrapper.find('[data-without-role]').exists()).toBe(false)
    expect(wrapper.find('[data-to-reconnect]').exists()).toBe(true)
  })
})

describe('CorporationAccessRequired for an unreadable ESI', () => {
  it('says the roles could not be read and offers to retry', async () => {
    const wrapper = mountCard(ESI_ERROR)

    expect(wrapper.get('[data-access-required]').attributes('data-access-state')).toBe('esi_error')
    expect(wrapper.find('[data-reconnect-link]').exists()).toBe(false)
    await wrapper.get('[data-recheck]').trigger('click')

    expect(wrapper.emitted('recheck')).toHaveLength(1)
  })
})
