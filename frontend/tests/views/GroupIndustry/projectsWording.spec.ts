import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

const FORMER_MODULE_NAME = /industrie de groupe|group industry/i

function visibleTexts(node: unknown, path = ''): Array<[string, string]> {
  if (typeof node === 'string') return [[path, node]]
  if (typeof node !== 'object' || node === null) return []
  return Object.entries(node).flatMap(([key, child]) => visibleTexts(child, path === '' ? key : `${path}.${key}`))
}

describe('Projects wording', () => {
  it.each([
    ['en', en],
    ['fr', fr],
  ])('names no text of the %s locale after the former group industry module', (_locale, messages) => {
    const namedAfterFormerModule = visibleTexts(messages).filter(([, text]) => FORMER_MODULE_NAME.test(text))

    expect(namedAfterFormerModule).toEqual([])
  })

  it.each([
    ['en', en.groupIndustry.title, 'Projects'],
    ['fr', fr.groupIndustry.title, 'Projets'],
  ])('titles the projects page in %s', (_locale, title, expectedTitle) => {
    expect(title).toBe(expectedTitle)
  })
})
