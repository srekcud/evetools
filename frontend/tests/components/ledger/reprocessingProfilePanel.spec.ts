import { enableAutoUnmount, mount } from '@vue/test-utils'
import ReprocessingProfilePanel from '@/components/ledger/ReprocessingProfilePanel.vue'
import type { ReprocessingFailure, ReprocessingProfile } from '@/composables/useReprocessingProfile'
import { i18nPlugin } from './miningLedgerHarness'
import {
  COHERENT_ORE_PROCESSING,
  ESI_ERROR_PROFILE,
  ICE_PROCESSING,
  LONG_NAME_ORE_PROCESSING,
  NPC_STATION_PROFILE,
  PILOT_ONE,
  PILOT_ONE_ID,
  PILOT_TWO,
  PILOT_TWO_ID,
  SIMPLE_ORE_PROCESSING,
  TATARA_PROFILE,
  UNCONFIGURED_PROFILE,
  reprocessingProfile,
} from './reprocessingProfileHarness'

enableAutoUnmount(afterEach)

function mountPanel(profile: ReprocessingProfile | null) {
  return mount(ReprocessingProfilePanel, {
    props: { profile, characters: [PILOT_ONE, PILOT_TWO] },
    global: { plugins: [i18nPlugin()] },
  })
}

type PanelWrapper = ReturnType<typeof mountPanel>

function field(wrapper: PanelWrapper, name: string) {
  return wrapper.get(`select[data-field="${name}"]`)
}

function optionsOf(wrapper: PanelWrapper, name: string): Array<[string, string]> {
  return field(wrapper, name)
    .findAll('option')
    .filter(option => option.attributes('value') !== '' && option.attributes('value') !== undefined)
    .map(option => [option.attributes('value') ?? '', option.text()])
}

function valuesOf(wrapper: PanelWrapper, name: string): string[] {
  return optionsOf(wrapper, name).map(([value]) => value)
}

function selectedValue(wrapper: PanelWrapper, name: string): string {
  return (field(wrapper, name).element as HTMLSelectElement).value
}

function skillLevel(wrapper: PanelWrapper, skill: 'reprocessing' | 'reprocessingEfficiency' | 'oreProcessing'): string {
  return wrapper.get(`[data-skill-level="${skill}"]`).text().trim()
}

describe('ReprocessingProfilePanel fields', () => {
  it('offers the characters of the user by their EVE character id', () => {
    const wrapper = mountPanel(NPC_STATION_PROFILE)

    expect(optionsOf(wrapper, 'character')).toEqual([
      [String(PILOT_ONE_ID), 'Ilyra Castell'],
      [String(PILOT_TWO_ID), 'Tovan Reske'],
    ])
  })

  it('offers the ore processing skills of the profile in the order of the API, long names in full', () => {
    const wrapper = mountPanel(UNCONFIGURED_PROFILE)

    expect(optionsOf(wrapper, 'oreProcessingSkill')).toEqual([
      [String(COHERENT_ORE_PROCESSING), 'Coherent Ore Processing'],
      [String(LONG_NAME_ORE_PROCESSING), 'Exceptionally Lustrous Hypothetical Moon Ore Processing With An Overly Long Name'],
      [String(ICE_PROCESSING), 'Ice Processing'],
      [String(SIMPLE_ORE_PROCESSING), 'Simple Ore Processing'],
    ])
  })

  it('offers the locations, rig tiers, securities and implants of the spec', () => {
    const wrapper = mountPanel(NPC_STATION_PROFILE)

    expect(valuesOf(wrapper, 'location')).toEqual(['npc_station', 'athanor', 'tatara'])
    expect(valuesOf(wrapper, 'rigTier')).toEqual(['none', 't1', 't2'])
    expect(valuesOf(wrapper, 'security')).toEqual(['highsec', 'lowsec', 'nullsec'])
    expect(valuesOf(wrapper, 'implant')).toEqual(['0', '1', '2', '4'])
  })

  it('starts from the saved settings of the profile', () => {
    const wrapper = mountPanel(TATARA_PROFILE)

    expect(['character', 'oreProcessingSkill', 'location', 'rigTier', 'security', 'implant'].map(name => selectedValue(wrapper, name)))
      .toEqual([String(PILOT_TWO_ID), String(COHERENT_ORE_PROCESSING), 'tatara', 't2', 'nullsec', '1'])
  })

  it('disables the rig tier and the security for an NPC station', () => {
    const wrapper = mountPanel(NPC_STATION_PROFILE)

    expect(field(wrapper, 'rigTier').attributes('disabled')).toBeDefined()
    expect(field(wrapper, 'security').attributes('disabled')).toBeDefined()
  })

  it('enables the rig tier and the security for a structure', () => {
    const wrapper = mountPanel(TATARA_PROFILE)

    expect(field(wrapper, 'rigTier').attributes('disabled')).toBeUndefined()
    expect(field(wrapper, 'security').attributes('disabled')).toBeUndefined()
  })
})

describe('ReprocessingProfilePanel computed yield', () => {
  it('shows the computed yield as a percentage with the levels read', () => {
    const wrapper = mountPanel(TATARA_PROFILE)

    expect(wrapper.get('[data-reprocess-yield]').text()).toContain('78.1')
    expect([skillLevel(wrapper, 'reprocessing'), skillLevel(wrapper, 'reprocessingEfficiency'), skillLevel(wrapper, 'oreProcessing')]).toEqual(['4', '3', '2'])
    expect(wrapper.find('[data-failure]').exists()).toBe(false)
  })

  it('shows level 0 as a known level', () => {
    const wrapper = mountPanel(reprocessingProfile({ reprocessingLevel: 0, reprocessingEfficiencyLevel: 2, oreProcessingLevel: 0, reprocessYield: 0.52 }))

    expect([skillLevel(wrapper, 'reprocessing'), skillLevel(wrapper, 'reprocessingEfficiency'), skillLevel(wrapper, 'oreProcessing')]).toEqual(['0', '2', '0'])
  })

  it('shows an unknown yield, unknown levels and the reason when the skills were unreadable', () => {
    const wrapper = mountPanel(ESI_ERROR_PROFILE)

    expect(wrapper.get('[data-reprocess-yield]').text()).toContain('—')
    expect([skillLevel(wrapper, 'reprocessing'), skillLevel(wrapper, 'reprocessingEfficiency'), skillLevel(wrapper, 'oreProcessing')]).toEqual(['—', '—', '—'])
    expect(wrapper.get('[data-failure]').attributes('data-failure')).toBe('esi_error')
    expect(wrapper.get('[data-failure]').text()).not.toBe('')
  })

  it('names a different reason for each failure', () => {
    const failures: ReprocessingFailure[] = ['missing_token', 'missing_scope', 'esi_error']

    const reasons = failures.map(failure => mountPanel({ ...ESI_ERROR_PROFILE, failure }).find(`[data-failure="${failure}"]`))

    expect(reasons.map(reason => reason.exists())).toEqual([true, true, true])
    expect(new Set(reasons.map(reason => reason.text())).size).toBe(3)
  })

  it('shows an unknown yield without reason and disables the refresh before any profile is saved', () => {
    const wrapper = mountPanel(UNCONFIGURED_PROFILE)

    expect(wrapper.get('[data-reprocess-yield]').text()).toContain('—')
    expect(wrapper.find('[data-failure]').exists()).toBe(false)
    expect(wrapper.get('button[data-action="refresh"]').attributes('disabled')).toBeDefined()
  })
})

describe('ReprocessingProfilePanel actions', () => {
  it('emits the chosen structure settings on save', async () => {
    const wrapper = mountPanel(NPC_STATION_PROFILE)

    await field(wrapper, 'character').setValue(String(PILOT_TWO_ID))
    await field(wrapper, 'oreProcessingSkill').setValue(String(ICE_PROCESSING))
    await field(wrapper, 'location').setValue('tatara')
    await field(wrapper, 'rigTier').setValue('t1')
    await field(wrapper, 'security').setValue('lowsec')
    await field(wrapper, 'implant').setValue('2')
    await wrapper.get('button[data-action="save"]').trigger('click')

    expect(wrapper.emitted('save')).toEqual([[{
      characterId: PILOT_TWO_ID,
      oreProcessingSkillTypeId: ICE_PROCESSING,
      location: 'tatara',
      rigTier: 't1',
      security: 'lowsec',
      implantPercent: 2,
    }]])
  })

  it('emits no rig tier nor security on save for an NPC station', async () => {
    const wrapper = mountPanel(TATARA_PROFILE)

    await field(wrapper, 'location').setValue('npc_station')
    await field(wrapper, 'implant').setValue('4')
    await wrapper.get('button[data-action="save"]').trigger('click')

    expect(wrapper.emitted('save')).toEqual([[{
      characterId: PILOT_TWO_ID,
      oreProcessingSkillTypeId: COHERENT_ORE_PROCESSING,
      location: 'npc_station',
      rigTier: null,
      security: null,
      implantPercent: 4,
    }]])
  })

  it('emits a refresh when the recompute button is clicked', async () => {
    const wrapper = mountPanel(TATARA_PROFILE)

    await wrapper.get('button[data-action="refresh"]').trigger('click')

    expect(wrapper.emitted('refresh')).toEqual([[]])
  })
})
