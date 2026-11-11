import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import fr from '@/i18n/locales/fr.json'
import CorpVisibilityPanel from '@/components/assets/CorpVisibilityPanel.vue'
import type { CorpAssetVisibility } from '@/types'

const ALICE_ID = '0199a1f0-0000-7000-8000-000000358001'
const BOB_ID = '0199a1f0-0000-7000-8000-000000358002'
const MINERALS_DIVISION = 1
const FUEL_DIVISION = 2
const AMMO_DIVISION = 3

function visibility(overrides: Partial<CorpAssetVisibility> = {}): CorpAssetVisibility {
  return {
    visibleDivisions: [FUEL_DIVISION],
    restrictedDivisions: { [MINERALS_DIVISION]: [{ id: BOB_ID, name: 'Bob Tamm' }] },
    shareBlueprintsOfHiddenDivisions: true,
    accessibleDivisions: [FUEL_DIVISION],
    players: [
      { id: ALICE_ID, name: 'Alice Voss' },
      { id: BOB_ID, name: 'Bob Tamm' },
    ],
    directorConnected: true,
    allDivisions: { [MINERALS_DIVISION]: 'Minerals', [FUEL_DIVISION]: 'Fuel', [AMMO_DIVISION]: 'Ammo' },
    isDirector: true,
    configuredByName: 'Aria Solberg',
    updatedAt: '2026-11-07T20:00:00+00:00',
    ...overrides,
  }
}

async function mountExpandedPanel(overrides: Partial<CorpAssetVisibility> = {}) {
  const i18n = createI18n({ legacy: false, locale: 'fr', messages: { fr } })
  const wrapper = mount(CorpVisibilityPanel, {
    global: { plugins: [i18n] },
    props: { visibility: visibility(overrides) },
  })
  await wrapper.get('[data-testid="visibility-panel-toggle"]').trigger('click')
  return wrapper
}

describe('CorpVisibilityPanel', () => {
  it('lets the director pick all members, a list of players or hidden per division and saves both lists', async () => {
    const wrapper = await mountExpandedPanel()

    const pressedModes = (division: number) =>
      (['all', 'list', 'hidden'] as const).map(mode =>
        wrapper.get(`[data-testid="division-${division}-mode-${mode}"]`).attributes('aria-pressed'),
      )
    expect(pressedModes(MINERALS_DIVISION)).toEqual(['false', 'true', 'false'])
    expect(pressedModes(FUEL_DIVISION)).toEqual(['true', 'false', 'false'])
    expect(pressedModes(AMMO_DIVISION)).toEqual(['false', 'false', 'true'])
    expect(wrapper.find(`[data-testid="division-${AMMO_DIVISION}-players"]`).exists()).toBe(false)

    await wrapper.get(`[data-testid="division-${AMMO_DIVISION}-mode-list"]`).trigger('click')
    expect(wrapper.find(`[data-testid="division-${AMMO_DIVISION}-players"]`).exists()).toBe(true)
    await wrapper.get(`[data-testid="division-${AMMO_DIVISION}-player-${ALICE_ID}"]`).trigger('click')
    await wrapper.get(`[data-testid="division-${FUEL_DIVISION}-mode-hidden"]`).trigger('click')
    await wrapper.get('[data-testid="save"]').trigger('click')

    expect(wrapper.emitted('save')).toEqual([[
      { visibleDivisions: [], restrictedDivisions: { [MINERALS_DIVISION]: [BOB_ID], [AMMO_DIVISION]: [ALICE_ID] }, shareBlueprintsOfHiddenDivisions: true },
    ]])
  })

  it('warns that the content of the corporation containers is unknown while no director is connected', async () => {
    const withoutDirector = await mountExpandedPanel({ directorConnected: false })
    const withDirector = await mountExpandedPanel({ directorConnected: true })

    expect(withoutDirector.find('[data-testid="no-director-warning"]').exists()).toBe(true)
    expect(withDirector.find('[data-testid="no-director-warning"]').exists()).toBe(false)
  })

  it('keeps the blueprints of hidden divisions shared by default and saves false once the director unchecks it', async () => {
    const wrapper = await mountExpandedPanel()
    const checkbox = wrapper.get<HTMLInputElement>('[data-testid="share-blueprints-checkbox"]')

    expect(checkbox.element.checked).toBe(true)
    expect(wrapper.get('[data-testid="save"]').attributes('disabled')).toBeDefined()

    await checkbox.setValue(false)
    expect(wrapper.get('[data-testid="save"]').attributes('disabled')).toBeUndefined()
    await wrapper.get('[data-testid="save"]').trigger('click')

    expect(wrapper.emitted('save')).toEqual([[
      { visibleDivisions: [FUEL_DIVISION], restrictedDivisions: { [MINERALS_DIVISION]: [BOB_ID] }, shareBlueprintsOfHiddenDivisions: false },
    ]])
  })

  it('shows the blueprint sharing state read-only to a member who is not a director', async () => {
    const shared = await mountExpandedPanel({ isDirector: false })
    const notShared = await mountExpandedPanel({ isDirector: false, shareBlueprintsOfHiddenDivisions: false })

    expect(shared.find('[data-testid="share-blueprints-checkbox"]').exists()).toBe(false)
    expect(shared.get('[data-testid="share-blueprints-state"]').text()).toContain(fr.assets.visibility.shareBlueprintsOn)
    expect(notShared.get('[data-testid="share-blueprints-state"]').text()).toContain(fr.assets.visibility.shareBlueprintsOff)
  })
})
