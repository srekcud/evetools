import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const stylesheet = readFileSync(resolve(__dirname, '../../src/style.css'), 'utf8')

const vueSources = import.meta.glob('../../src/**/*.vue', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

const FORBIDDEN_TEXT_SIZES = ['text-[10px]', 'text-[11px]']

describe('typographic scale in style.css', () => {
  it.each([
    ['--text-body', '0.875rem'],
    ['--text-table-cell', '0.8125rem'],
  ])('defines %s as %s', (token, size) => {
    expect(stylesheet).toMatch(new RegExp(`${token}:\\s*${size.replace('.', '\\.')};`))
  })

  it('does not override the text-xs size reserved for secondary labels and badges', () => {
    expect(stylesheet).not.toMatch(/--text-xs:/)
  })
})

describe('vue sources', () => {
  it('scans the vue files of src', () => {
    expect(Object.keys(vueSources).length).toBeGreaterThan(50)
  })

  it.each(FORBIDDEN_TEXT_SIZES)('contain no %s', (forbiddenClass) => {
    const offendingFiles = Object.entries(vueSources)
      .filter(([, source]) => source.includes(forbiddenClass))
      .map(([path]) => path)

    expect(offendingFiles).toEqual([])
  })
})
