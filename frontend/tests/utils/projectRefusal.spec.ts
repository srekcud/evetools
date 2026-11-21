import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { projectRefusalMessage } from '@/utils/projectRefusal'

const WORKED_LINE = 'The line "Fernite Composite" carries a linked job, a purchase or a contribution and cannot be removed from the plan.'
const OVER_RECEIVED = 'The line "Fernite Composite" has already received 40, more than the 25 the plan now needs.'
const OVER_LINKED = 'The line "Hobgoblin II" has 12 runs linked to ESI jobs, more than the 8 the plan now needs.'
const OVER_MARKED = 'The line "Hobgoblin II" has 9 runs marked, more than the 4 the plan now needs.'
const TOO_MANY_LINES = 'The production plan has 3200 BOM lines, above the limit of 3000 BOM lines per project.'
const COMPLETED_BOM = 'The BOM of a completed project cannot be rebuilt.'
const COMPLETED_POLICY = 'The structure policy of a completed project cannot be changed'
const COMPLETED_BLACKLIST = 'The blacklist of a completed project cannot be changed'
const BLACKLIST_DRAFT_ONLY = 'The blacklist of a project can only be changed while it is a draft'
const UNKNOWN_REFUSAL = 'Something the server never said before'

function translatorOf(locale: 'en' | 'fr') {
  const { t } = createI18n({ legacy: false, locale, messages: { en, fr } }).global
  return (key: string, values: Record<string, string>): string => t(key, values)
}

describe('projectRefusalMessage', () => {
  it.each([
    ['a worked line that would be removed', WORKED_LINE, 'The line "Fernite Composite" has a linked job, a purchase or a contribution: it cannot be removed from the material list.'],
    ['a line that already received more than the plan needs', OVER_RECEIVED, 'The line "Fernite Composite" has already received 40, more than the 25 the new material list needs.'],
    ['a line with more runs linked to ESI jobs than the plan needs', OVER_LINKED, 'The line "Hobgoblin II" has 12 runs linked to ESI jobs, more than the 8 the new material list needs.'],
    ['a line with more runs marked than the plan needs', OVER_MARKED, 'The line "Hobgoblin II" has 9 runs marked, more than the 4 the new material list needs.'],
    ['a plan above the line limit', TOO_MANY_LINES, 'The material list would have 3200 lines, above the limit of 3000 lines per project.'],
    ['the BOM of a completed project', COMPLETED_BOM, 'The project is completed: its material list can no longer change.'],
    ['the structure policy of a completed project', COMPLETED_POLICY, 'The project is completed: its material list can no longer change.'],
    ['the blacklist of a completed project', COMPLETED_BLACKLIST, 'The project is completed: its material list can no longer change.'],
    ['the blacklist categories outside a draft', BLACKLIST_DRAFT_ONLY, 'Blacklisted categories can only change while the project is a draft.'],
  ])('translates the refusal of %s in English', (_case, message, expected) => {
    expect(projectRefusalMessage(message, translatorOf('en'))).toBe(expected)
  })

  it.each([
    [WORKED_LINE, 'Fernite Composite'],
    [OVER_RECEIVED, '40'],
    [OVER_LINKED, 'Hobgoblin II'],
    [OVER_MARKED, 'Hobgoblin II'],
    [TOO_MANY_LINES, '3200'],
    [COMPLETED_BOM, 'terminé'],
    [COMPLETED_POLICY, 'terminé'],
    [COMPLETED_BLACKLIST, 'terminé'],
    [BLACKLIST_DRAFT_ONLY, 'brouillon'],
  ])('translates the refusal "%s" in French, with the values of the server and without its English text', (message, expectedPart) => {
    const translated = projectRefusalMessage(message, translatorOf('fr'))

    expect(translated).toContain(expectedPart)
    expect(translated).not.toBe(message)
    expect(translated).not.toMatch(/\bThe (line|production plan|BOM|blacklist|structure)\b/)
  })

  it('keeps the message of the server when it is not a known refusal, never hides it', () => {
    expect(projectRefusalMessage(UNKNOWN_REFUSAL, translatorOf('en'))).toBe(UNKNOWN_REFUSAL)
    expect(projectRefusalMessage(UNKNOWN_REFUSAL, translatorOf('fr'))).toBe(UNKNOWN_REFUSAL)
  })
})
