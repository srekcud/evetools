import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PiColonyCard from '@/components/planetary/PiColonyCard.vue'
import type { Colony } from '@/stores/planetary'

const CPU_GAUGE = '[data-testid="power-gauge-cpu"]'
const PG_GAUGE = '[data-testid="power-gauge-pg"]'
const CPU_BAR = '[data-testid="power-gauge-cpu-bar"]'
const PG_BAR = '[data-testid="power-gauge-pg-bar"]'
const UPGRADE_BADGE = '[data-testid="cc-upgrade-badge"]'
const COMMAND_CENTER_BLOCK = '[data-testid="command-center-block"]'
const UPGRADE_SIGNAL = '[data-testid="cc-upgrade-signal"]'
const SKILL_LEVEL = '[data-testid="command-center-skill"]'
const SKILL_UNKNOWN = '[data-testid="command-center-skill-unknown"]'

const BARREN_COLONY: Colony = {
  id: 'colony-barren-elite',
  characterId: 2_119_000_011,
  characterName: 'Ilyra Castell-Vanterwyck of the Deep Core Verhoeven Syndicate',
  planetId: 40_009_077,
  planetName: 'Jita IV',
  planetType: 'barren',
  solarSystemId: 30_000_142,
  solarSystemName: 'Jita',
  solarSystemSecurity: 0.9,
  upgradeLevel: 3,
  cpuUsed: 4_000,
  cpuMax: 25_415,
  powergridUsed: 2_300,
  powergridMax: 19_000,
  commandCenterSkillLevel: 4,
  commandCenterUpgradable: true,
  numPins: 4,
  lastUpdate: '2026-11-26T09:00:00+00:00',
  cachedAt: '2026-11-26T09:05:00+00:00',
  extractorCount: 0,
  factoryCount: 2,
  activeExtractors: 0,
  nearestExpiry: null,
  factoryProgress: null,
  status: 'idle',
  pins: [],
  routes: [],
}

enableAutoUnmount(afterEach)

function mountCard(overrides: Partial<Colony> = {}, expanded = false) {
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(PiColonyCard, {
    global: { plugins: [i18n] },
    props: { colony: { ...BARREN_COLONY, ...overrides }, expanded },
  })
}

function existing(wrapper: VueWrapper, selector: string) {
  const found = wrapper.find(selector)
  expect(found.exists()).toBe(true)
  return found
}

function figures(text: string): string {
  return text.replace(/[\s,\u00a0\u202f]/g, '')
}

describe('PiColonyCard CPU and powergrid gauges', () => {
  it('shows a colony well under its limits as cyan gauges with used, maximum and free figures', () => {
    const wrapper = mountCard()

    const cpu = existing(wrapper, CPU_GAUGE)
    const powergrid = existing(wrapper, PG_GAUGE)
    expect(cpu.attributes('data-state')).toBe('normal')
    expect(powergrid.attributes('data-state')).toBe('normal')
    expect(figures(cpu.text())).toContain('4000')
    expect(figures(cpu.text())).toContain('25415')
    expect(figures(cpu.text())).toContain('21415')
    expect(figures(powergrid.text())).toContain('2300')
    expect(figures(powergrid.text())).toContain('19000')
    expect(figures(powergrid.text())).toContain('16700')
  })

  it('turns the gauge amber from 85 percent of the maximum and keeps the other gauge cyan', () => {
    const wrapper = mountCard({ powergridUsed: 5_350, powergridMax: 6_000 })

    const powergrid = existing(wrapper, PG_GAUGE)
    expect(powergrid.attributes('data-state')).toBe('warning')
    expect(figures(powergrid.text())).toContain('89%')
    expect(figures(powergrid.text())).toContain('650')
    expect(existing(wrapper, CPU_GAUGE).attributes('data-state')).toBe('normal')
  })

  it('keeps exactly 85 percent and a fully used maximum amber and only goes red above 100 percent', () => {
    const wrapper = mountCard({ cpuUsed: 1_700, cpuMax: 2_000, powergridUsed: 6_000, powergridMax: 6_000 })

    expect(existing(wrapper, CPU_GAUGE).attributes('data-state')).toBe('warning')
    expect(existing(wrapper, PG_GAUGE).attributes('data-state')).toBe('warning')
    expect(figures(existing(wrapper, PG_GAUGE).text())).toContain('100%')
  })

  it('turns the gauge red above 100 percent and shows the negative free figure as is', () => {
    const wrapper = mountCard({ cpuUsed: 1_800, cpuMax: 1_675 })

    const cpu = existing(wrapper, CPU_GAUGE)
    expect(cpu.attributes('data-state')).toBe('over')
    expect(figures(cpu.text())).toContain('-125')
    expect(figures(cpu.text())).toContain('107%')
  })

  it('shows unknown without any gauge bar when the figures are unknown, never zero', () => {
    const wrapper = mountCard({ cpuUsed: null, cpuMax: null, powergridUsed: null, powergridMax: null })

    expect(existing(wrapper, CPU_GAUGE).attributes('data-state')).toBe('unknown')
    expect(existing(wrapper, PG_GAUGE).attributes('data-state')).toBe('unknown')
    expect(existing(wrapper, CPU_GAUGE).text()).toMatch(/unknown/i)
    expect(existing(wrapper, PG_GAUGE).text()).toMatch(/unknown/i)
    expect(wrapper.find(CPU_BAR).exists()).toBe(false)
    expect(wrapper.find(PG_BAR).exists()).toBe(false)
  })

  it('warns in the tooltip of both gauges that links are not counted', () => {
    const wrapper = mountCard()

    expect(existing(wrapper, CPU_GAUGE).attributes('title')).toMatch(/links not counted/i)
    expect(existing(wrapper, PG_GAUGE).attributes('title')).toMatch(/links not counted/i)
  })
})

describe('PiColonyCard command center upgrade signal', () => {
  it('shows the amber badge in the collapsed row when the character skill exceeds the command center level', () => {
    const wrapper = mountCard({ upgradeLevel: 3, commandCenterSkillLevel: 4, commandCenterUpgradable: true })

    expect(wrapper.find(UPGRADE_BADGE).exists()).toBe(true)
  })

  it('shows no badge when the skill equals the level or when the skill is unknown', () => {
    const equal = mountCard({ upgradeLevel: 3, commandCenterSkillLevel: 3, commandCenterUpgradable: false })
    const unknown = mountCard({ upgradeLevel: 3, commandCenterSkillLevel: null, commandCenterUpgradable: null })

    expect(equal.find(UPGRADE_BADGE).exists()).toBe(false)
    expect(unknown.find(UPGRADE_BADGE).exists()).toBe(false)
  })

  it('describes the command center level and the trained skill in the expanded detail', () => {
    const wrapper = mountCard({ upgradeLevel: 3, commandCenterSkillLevel: 4, commandCenterUpgradable: true }, true)

    const block = wrapper.find(COMMAND_CENTER_BLOCK)
    expect(block.exists()).toBe(true)
    expect(block.text()).toMatch(/Level\s*3/)
    expect(block.text()).toContain('Command Center Upgrades')
    expect(wrapper.find(SKILL_LEVEL).text()).toMatch(/\b(IV|4)\b/)
  })

  it('repeats the upgrade signal in the command center block only when the colony is upgradable', () => {
    const upgradable = mountCard({ upgradeLevel: 3, commandCenterSkillLevel: 4, commandCenterUpgradable: true }, true)
    const notUpgradable = mountCard({ upgradeLevel: 3, commandCenterSkillLevel: 3, commandCenterUpgradable: false }, true)

    expect(existing(upgradable, COMMAND_CENTER_BLOCK).find(UPGRADE_SIGNAL).exists()).toBe(true)
    expect(notUpgradable.find(COMMAND_CENTER_BLOCK).exists()).toBe(true)
    expect(notUpgradable.find(UPGRADE_SIGNAL).exists()).toBe(false)
  })

  it('says the skill is not synchronized and gives no signal when the skill is unknown', () => {
    const wrapper = mountCard({ upgradeLevel: 3, commandCenterSkillLevel: null, commandCenterUpgradable: null }, true)

    expect(wrapper.find(COMMAND_CENTER_BLOCK).exists()).toBe(true)
    expect(wrapper.find(SKILL_UNKNOWN).exists()).toBe(true)
    expect(wrapper.find(SKILL_LEVEL).exists()).toBe(false)
    expect(wrapper.find(UPGRADE_SIGNAL).exists()).toBe(false)
    expect(wrapper.find(UPGRADE_BADGE).exists()).toBe(false)
  })
})
