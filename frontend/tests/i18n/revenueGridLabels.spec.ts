import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

function leafKeys(node: unknown, prefix = ''): string[] {
  if (typeof node !== 'object' || node === null) return [prefix]
  return Object.entries(node).flatMap(([key, child]) => leafKeys(child, prefix === '' ? key : `${prefix}.${key}`))
}

describe('revenue grid labels', () => {
  it('has the same keys in English and French', () => {
    expect(leafKeys(fr.revenue).sort()).toEqual(leafKeys(en.revenue).sort())
  })

  it('words the grid differently in French', () => {
    expect(fr.revenue.grid.regularity).not.toBe(en.revenue.grid.regularity)
    expect(fr.revenue.todo.title).not.toBe(en.revenue.todo.title)
  })
})
