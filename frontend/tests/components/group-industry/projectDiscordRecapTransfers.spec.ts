import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { buildProjectDiscordRecap } from '@/components/group-industry/projectDiscordRecap'
import type { ProjectSettlement } from '@/stores/group-industry/types'
import { completedProject, profitableDistribution } from './projectDiscordRecapFixtures'
import { SETTLEMENT_REFERENCE, projectSettlement, projectTransfer } from './projectSettlementFixtures'

function recapOf(settlement: ProjectSettlement | null, locale: 'en' | 'fr' = 'en'): string[] {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return buildProjectDiscordRecap({
    project: completedProject(),
    distribution: profitableDistribution(),
    settlement,
    t: i18n.global.t,
    formatIsk: value => `${value} ISK`,
    pilotName: name => name ?? 'Deleted pilot',
  }).split('\n')
}

describe('buildProjectDiscordRecap with the transfers of the project', () => {
  it('puts the payment reference after the participants and lists every transfer after the payouts', () => {
    const lines = recapOf(projectSettlement({
      transfers: [
        projectTransfer(),
        projectTransfer({ fromParticipantId: 'member-corin', fromName: 'Corin Vale', amount: 5_000.5 }),
      ],
    }))

    expect(lines[1]).toBe(`-# Industrial project · 2 participants · ref. ${SETTLEMENT_REFERENCE}`)
    const fenceEnd = lines.lastIndexOf('```')
    expect(lines.slice(fenceEnd + 1)).toEqual([
      `**Transfers** · reference \`${SETTLEMENT_REFERENCE}\``,
      '- Aria Solberg → Brann Holt: **277500 ISK**',
      '- Corin Vale → Brann Holt: **5000.5 ISK**',
      '-# EVE Tools',
    ])
  })

  it('names a deleted pilot in a transfer', () => {
    const lines = recapOf(projectSettlement({ transfers: [projectTransfer({ fromName: null })] }))

    expect(lines).toContain('- Deleted pilot → Brann Holt: **277500 ISK**')
  })

  it('keeps the reference but writes no transfer block when the transfers are not known', () => {
    const lines = recapOf(projectSettlement({ transfers: null, transfersUnavailableReason: 'unsold_products' }))

    expect(lines[1]).toContain(`ref. ${SETTLEMENT_REFERENCE}`)
    expect(lines.some(line => line.startsWith('**Transfers**'))).toBe(false)
  })

  it('writes no transfer block when nothing has to be transferred', () => {
    expect(recapOf(projectSettlement({ transfers: [] })).some(line => line.startsWith('**Transfers**'))).toBe(false)
  })

  it('writes the recap of a project without settlement exactly as before', () => {
    const lines = recapOf(null)

    expect(lines[1]).toBe('-# Industrial project · 2 participants')
    expect(lines.some(line => line.startsWith('**Transfers**'))).toBe(false)
  })

  it('writes the transfer block in French', () => {
    const lines = recapOf(projectSettlement(), 'fr')

    expect(lines.slice(lines.lastIndexOf('```') + 1)[0]).toBe(`**Virements** · motif \`${SETTLEMENT_REFERENCE}\``)
  })
})
