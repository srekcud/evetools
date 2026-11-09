import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import { buildProjectDiscordRecap } from '@/components/group-industry/projectDiscordRecap'
import type { Distribution } from '@/stores/group-industry/types'
import { completedProject, profitableDistribution } from './projectDiscordRecapFixtures'

function salesLineOf(distribution: Distribution): string | undefined {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const recap = buildProjectDiscordRecap({
    project: completedProject(),
    distribution,
    t: i18n.global.t,
    formatIsk: value => `${value} ISK`,
    pilotName: name => name ?? 'Deleted pilot',
  })
  return recap.split('\n').find(line => line.startsWith('> '))
}

describe('buildProjectDiscordRecap with contract fees', () => {
  it('adds the fees of the contracts after the sales tax when some were paid', () => {
    expect(salesLineOf(profitableDistribution({ contractRevenue: 500_000, contractFees: 40_000 }))).toBe(
      '> Sales 2000000 ISK · broker fee 70000 ISK · sales tax 70000 ISK · contract fees 40000 ISK · cost 1200000 ISK',
    )
  })

  it('says the contract fees are unknown when the journal has no line for a contract', () => {
    expect(salesLineOf(profitableDistribution({ contractRevenue: 500_000, contractFees: null }))).toContain('contract fees unknown')
  })

  it('leaves the line as before when the contracts of the project cost no fee', () => {
    expect(salesLineOf(profitableDistribution({ contractRevenue: 500_000, contractFees: 0 }))).not.toContain('contract fees')
  })

  it('leaves the line as before for a project without any contract', () => {
    expect(salesLineOf(profitableDistribution({ contractFees: 0 }))).toBe(
      '> Sales 2000000 ISK · broker fee 70000 ISK · sales tax 70000 ISK · cost 1200000 ISK',
    )
  })
})
