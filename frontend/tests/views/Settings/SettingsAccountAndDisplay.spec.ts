import { enableAutoUnmount, flushPromises, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { callsTo, mountSettingsAt, stubBackend } from './settingsHarness'
import { present } from '../../present'

const VISIBILITY_URL = '/api/me/corporation/assets/visibility'
const ALICE_ID = '0199a1f0-0000-7000-8000-000000358001'
const BOB_ID = '0199a1f0-0000-7000-8000-000000358002'
const MINERALS_DIVISION = 1
const FUEL_DIVISION = 2
const AMMO_DIVISION = 3
const HIDDEN_MODULES_STORAGE_KEY = 'evetools_hidden_modules'
const DATE_FORMAT_STORAGE_KEY = 'evetools_date_format'
const LOCALE_STORAGE_KEY = 'locale'

function corporationVisibility(isDirector: boolean) {
  return {
    visibleDivisions: [FUEL_DIVISION],
    restrictedDivisions: { [MINERALS_DIVISION]: [{ id: BOB_ID, name: 'Bob Tamm' }] },
    accessibleDivisions: [FUEL_DIVISION],
    players: [
      { id: ALICE_ID, name: 'Alice Voss' },
      { id: BOB_ID, name: 'Bob Tamm' },
    ],
    directorConnected: true,
    allDivisions: { [MINERALS_DIVISION]: 'Minerals', [FUEL_DIVISION]: 'Fuel', [AMMO_DIVISION]: 'Ammo' },
    isDirector,
    configuredByName: 'Aria Solberg',
    updatedAt: '2026-11-07T20:00:00+00:00',
  }
}

function moduleCheckbox(wrapper: VueWrapper, label: string): DOMWrapper<HTMLInputElement> {
  const row = wrapper.findAll('label').find(candidate => candidate.text() === label)
  expect(row, `module checkbox ${label}`).toBeDefined()
  return present(row).find<HTMLInputElement>('input[type="checkbox"]')
}

function moduleLabels(wrapper: VueWrapper): string[] {
  return wrapper.findAll('label').filter(label => label.find('input[type="checkbox"]').exists()).map(label => label.text())
}

function buttonWithText(wrapper: VueWrapper, text: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === text)
  expect(button, `button ${text}`).toBeDefined()
  return present(button)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  localStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('Settings account section', () => {
  it('lets a director set the corporation inventory visibility and saves it to the same endpoint as the inventory page', async () => {
    const calls = stubBackend({ [`GET ${VISIBILITY_URL}`]: corporationVisibility(true), [`PUT ${VISIBILITY_URL}`]: {} })
    const { wrapper } = await mountSettingsAt('/settings/account')

    await wrapper.get('[data-testid="visibility-panel-toggle"]').trigger('click')
    await wrapper.get(`[data-testid="division-${AMMO_DIVISION}-mode-all"]`).trigger('click')
    await wrapper.get('[data-testid="save"]').trigger('click')
    await flushPromises()

    expect(callsTo(calls, 'PUT', VISIBILITY_URL).map(call => call.body)).toEqual([
      { visibleDivisions: [FUEL_DIVISION, AMMO_DIVISION], restrictedDivisions: { [MINERALS_DIVISION]: [BOB_ID] } },
    ])
  })

  it('shows an unavailability text instead of the visibility panel for a user who is not a director', async () => {
    stubBackend({ [`GET ${VISIBILITY_URL}`]: corporationVisibility(false) })
    const { wrapper } = await mountSettingsAt('/settings/account')

    expect(wrapper.find('[data-testid="visibility-panel-toggle"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="settings-account-unavailable"]').text()).not.toBe('')
  })
})

describe('Settings display section', () => {
  it('lists the seven hideable modules without Account, and hides and shows them under the identifiers already stored', async () => {
    localStorage.setItem(HIDDEN_MODULES_STORAGE_KEY, JSON.stringify(['mining-operations', 'market', 'appraisal']))
    stubBackend({})
    const { wrapper } = await mountSettingsAt('/settings/display')
    const initiallyChecked = ['Home', 'Industry', 'Mining', 'PVE', 'Planetary', 'Commerce', 'Wealth'].map(label => moduleCheckbox(wrapper, label).element.checked)

    await moduleCheckbox(wrapper, 'Mining').trigger('change')
    const afterShowingMining = localStorage.getItem(HIDDEN_MODULES_STORAGE_KEY)
    await moduleCheckbox(wrapper, 'Commerce').trigger('change')
    const afterShowingCommerce = localStorage.getItem(HIDDEN_MODULES_STORAGE_KEY)
    await moduleCheckbox(wrapper, 'Industry').trigger('change')

    expect(moduleLabels(wrapper)).toEqual(['Home', 'Industry', 'Mining', 'PVE', 'Planetary', 'Commerce', 'Wealth'])
    expect(initiallyChecked).toEqual([true, true, false, true, true, false, true])
    expect(afterShowingMining).toBe('["market","appraisal"]')
    expect(afterShowingCommerce).toBe('[]')
    expect(localStorage.getItem(HIDDEN_MODULES_STORAGE_KEY)).toBe('["industry"]')
  })

  it('switches the interface language and stores it under the locale key', async () => {
    stubBackend({})
    const { wrapper, i18n } = await mountSettingsAt('/settings/display')

    await buttonWithText(wrapper, 'Francais').trigger('click')

    expect(i18n.global.locale.value).toBe('fr')
    expect(localStorage.getItem(LOCALE_STORAGE_KEY)).toBe('fr')
  })

  it('stores the chosen date format under the date format key', async () => {
    stubBackend({})
    const { wrapper } = await mountSettingsAt('/settings/display')

    await buttonWithText(wrapper, 'MM/DD/YY').trigger('click')
    const afterUs = localStorage.getItem(DATE_FORMAT_STORAGE_KEY)
    await buttonWithText(wrapper, 'JJ/MM/AA').trigger('click')

    expect([afterUs, localStorage.getItem(DATE_FORMAT_STORAGE_KEY)]).toEqual(['us', 'eu'])
  })
})
