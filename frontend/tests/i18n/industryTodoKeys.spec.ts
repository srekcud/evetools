import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

function leafKeys(node: unknown, prefix = ''): string[] {
  if (typeof node === 'string') {
    return [prefix]
  }
  if (typeof node !== 'object' || node === null) {
    return []
  }
  return Object.entries(node).flatMap(([key, child]) => leafKeys(child, prefix === '' ? key : `${prefix}.${key}`))
}

function leafValues(node: unknown): string[] {
  if (typeof node === 'string') {
    return [node]
  }
  if (typeof node !== 'object' || node === null) {
    return []
  }
  return Object.values(node).flatMap(leafValues)
}

function todoMessages(locale: typeof en | typeof fr): unknown {
  return (locale as Record<string, unknown>).industryTodo
}

describe('industry to do page messages', () => {
  it('names the module tab in English and French', () => {
    expect([en.industryModule.tabs, fr.industryModule.tabs].map(tabs => (tabs as Record<string, string>).todo)).toEqual(['To do', 'À faire'])
  })

  it('has the same non empty industryTodo keys in English and French', () => {
    const englishKeys = leafKeys(todoMessages(en)).sort()
    const frenchKeys = leafKeys(todoMessages(fr)).sort()

    expect({
      englishCount: englishKeys.length > 0,
      sameKeys: englishKeys,
      blankValues: [...leafValues(todoMessages(en)), ...leafValues(todoMessages(fr))].filter(value => value.trim() === ''),
    }).toEqual({ englishCount: true, sameKeys: frenchKeys, blankValues: [] })
  })
})
