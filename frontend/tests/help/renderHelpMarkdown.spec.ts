import { renderHelpMarkdown } from '@/help/renderHelpMarkdown'

function anchorIdsOf(html: string): string[] {
  return [...html.matchAll(/id="([^"]+)"/g)].map(match => match[1])
}

describe('renderHelpMarkdown', () => {
  it.each([
    ['removes accents and lowercases', '# Ajouter une structure', '<h1 id="ajouter-une-structure">'],
    ['collapses punctuation into single dashes and trims the edges', '## Prix & marché (Jita)', '<h2 id="prix-marche-jita">'],
    ['falls back to section when nothing alphanumeric remains', '### ???', '<h3 id="section">'],
  ])('gives the heading an anchor that %s', (_case, source, expectedOpeningTag) => {
    expect(renderHelpMarkdown(source)).toContain(expectedOpeningTag)
  })

  it('numbers the anchors of repeated headings in the same page', () => {
    const html = renderHelpMarkdown('## Notes\n\ntexte\n\n## Notes\n\ntexte\n\n## Notes')

    expect(anchorIdsOf(html)).toEqual(['notes', 'notes-2', 'notes-3'])
  })

  it('renders a GFM table as an HTML table', () => {
    const html = renderHelpMarkdown('| a | b |\n|---|---|\n| 1 | 2 |')

    expect(html).toContain('<table>')
  })
})
