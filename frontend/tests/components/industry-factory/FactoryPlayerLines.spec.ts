import { enableAutoUnmount } from '@vue/test-utils'
import FactoryPlayerLines from '@/components/industry-factory/FactoryPlayerLines.vue'
import { useSlotsStore } from '@/stores/industry/slots'
import type { SlotTrackerData } from '@/stores/industry/types'
import { SLOT_CHARACTERS, SLOTS_URL } from '../../industryFactoryFixtures'
import { isUnknown, mountFactory } from './mountFactory'

enableAutoUnmount(afterEach)

const SLOTS: SlotTrackerData = {
  globalKpis: {
    manufacturing: { used: 5, max: 8, percent: 62 },
    reaction: { used: 3, max: 5, percent: 60 },
    science: { used: 2, max: 4, percent: 50 },
  },
  characters: SLOT_CHARACTERS,
  timeline: [],
  skillsMayBeStale: false,
  unknownSkillsCharacterCount: 1,
  jobsSyncedAt: null,
  jobsSyncIntervalSeconds: 1800,
}

describe('FactoryPlayerLines', () => {
  it('shows the lines of a known character and the unknown label, never a zero, for a character with unknown skills', async () => {
    const { wrapper } = await mountFactory(FactoryPlayerLines, {
      answers: { [`GET ${SLOTS_URL}`]: SLOTS },
      seed: () => {
        useSlotsStore().data = SLOTS
      },
    })

    const rows = wrapper.findAll('[data-testid="factory-player-row"]')
    expect(rows).toHaveLength(2)
    const [aria, brann] = rows
    expect(aria.text()).toContain('Aria Solberg')
    expect(aria.text()).toContain('8')
    expect(aria.text()).toContain('5')
    expect(isUnknown(aria)).toBe(false)
    expect(brann.text()).toContain('Brann Holt')
    expect(isUnknown(brann)).toBe(true)
    expect(brann.text()).not.toMatch(/\b0\b/)
  })
})
