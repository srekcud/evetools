import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { buildProjectDiscordRecap } from '@/components/group-industry/projectDiscordRecap'
import type { Distribution, GroupProject } from '@/stores/group-industry/types'
import { messageOf, type TestLocale } from '../../localeTexts'
import {
  HAMMERHEAD,
  HORNET,
  completedProject,
  memberDistribution,
  profitableDistribution,
} from './projectDiscordRecapFixtures'

const FENCE = '```'
const PARTICIPANT_NAME_WIDTH = 20

function recapOf(project: GroupProject, distribution: Distribution, locale: TestLocale = 'en'): string {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return buildProjectDiscordRecap({
    project,
    distribution,
    t: i18n.global.t,
    formatIsk: value => `${value} ISK`,
    pilotName: name => name ?? messageOf(locale, 'common.deletedPilot'),
  })
}

function tableLines(recap: string): string[] {
  const lines = recap.split('\n')
  const opening = lines.indexOf(FENCE)
  const closing = lines.indexOf(FENCE, opening + 1)
  return lines.slice(opening + 1, closing)
}

describe('buildProjectDiscordRecap', () => {
  it('writes the product, the profit, the margin, the sales breakdown and the payouts in Discord markdown', () => {
    const recap = recapOf(completedProject(), profitableDistribution())

    expect(recap.split('\n').filter(line => !tableLines(recap).includes(line))).toEqual([
      '## 🏭 Sabre run · completed',
      '-# Industrial project · 2 participants',
      '',
      '**50 × Hammerhead II** · ME 2 / TE 4',
      '**Profit 660000 ISK** · margin **55.0%**',
      '> Sales 2000000 ISK · broker fee 70000 ISK · sales tax 70000 ISK · cost 1200000 ISK',
      '',
      '**Payouts**',
      FENCE,
      FENCE,
      '-# EVE Tools',
    ])
  })

  it('lists every product of a multi-product project on its own line', () => {
    const recap = recapOf(completedProject({ items: [HAMMERHEAD, HORNET] }), profitableDistribution())

    expect(recap.split('\n').filter(line => line.startsWith('**') && line.includes(' × '))).toEqual([
      '**50 × Hammerhead II** · ME 2 / TE 4',
      '**20 × Hornet II** · ME 0 / TE 0',
    ])
  })

  it('aligns the payout table on one width with a total row', () => {
    const lines = tableLines(recapOf(completedProject(), profitableDistribution()))

    expect(lines.map(line => line.length)).toEqual(lines.map(() => lines[0].length))
    expect(lines.map(line => line.split(/\s{2,}/).map(cell => cell.trim()))).toEqual([
      ['Participant', 'Costs', 'Payout'],
      ['Aria Solberg', '800000 ISK', '1240000 ISK'],
      ['Brann Holt', '400000 ISK', '620000 ISK'],
      ['Total', '1200000 ISK', '1860000 ISK'],
    ])
  })

  it('shortens a long participant name with an ellipsis and names a deleted pilot', () => {
    const longName = 'Aurelius Maximilian Valerian Third'
    const lines = tableLines(recapOf(completedProject(), profitableDistribution({
      members: [
        memberDistribution({ characterName: longName }),
        memberDistribution({ memberId: 'member-gone', characterName: null }),
      ],
    })))

    expect(lines.slice(1, 3).map(line => line.slice(0, PARTICIPANT_NAME_WIDTH))).toEqual([
      `${longName.slice(0, PARTICIPANT_NAME_WIDTH - 1)}…`,
      'Deleted pilot       ',
    ])
  })

  it('says unknown, never zero, when the production cost makes the profit and the margin unknown', () => {
    const recap = recapOf(completedProject(), profitableDistribution({
      totalProjectCost: null,
      marginPercent: null,
      members: [memberDistribution({ totalCostsEngaged: null, profitPart: null, payoutTotal: null })],
    }))

    expect(recap.split('\n').filter(line => line.startsWith('**Profit') || line.startsWith('>'))).toEqual([
      '**Profit unknown** · margin **unknown**',
      '> Sales 2000000 ISK · broker fee 70000 ISK · sales tax 70000 ISK · cost unknown',
    ])
    expect(tableLines(recap).slice(1).map(line => line.split(/\s{2,}/).map(cell => cell.trim()))).toEqual([
      ['Aria Solberg', 'unknown', 'unknown'],
      ['Total', 'unknown', 'unknown'],
    ])
  })

  it('says unknown for unknown seller fees instead of a zero fee', () => {
    const recap = recapOf(completedProject(), profitableDistribution({ brokerFee: null, salesTax: null, netRevenue: null, marginPercent: null }))

    expect(recap.split('\n').filter(line => line.startsWith('**Profit') || line.startsWith('>'))).toEqual([
      '**Profit unknown** · margin **unknown**',
      '> Sales 2000000 ISK · broker fee unknown · sales tax unknown · cost 1200000 ISK',
    ])
  })

  it('names an unnamed project and counts a single participant in the singular', () => {
    const recap = recapOf(completedProject({ name: null }), profitableDistribution({ members: [memberDistribution()] }))

    expect(recap.split('\n').slice(0, 2)).toEqual([
      `## 🏭 ${messageOf('en', 'groupIndustry.discordRecap.unnamedProject')} · completed`,
      '-# Industrial project · 1 participant',
    ])
  })

  it('writes the recap in French when the locale is French', () => {
    const recap = recapOf(completedProject(), profitableDistribution(), 'fr')

    expect(recap.split('\n').filter(line => line.startsWith('## ') || line.startsWith('-#') || line.startsWith('**Profit'))).toEqual([
      '## 🏭 Sabre run · terminé',
      '-# Projet industriel · 2 participants',
      '**Profit 660000 ISK** · marge **55.0%**',
      '-# EVE Tools',
    ])
  })
})
