import { Marked } from 'marked'

const FALLBACK_ANCHOR = 'section'

function anchorOf(headingText: string): string {
  const anchor = headingText
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
  return anchor === '' ? FALLBACK_ANCHOR : anchor
}

export function renderHelpMarkdown(source: string): string {
  const anchorCounts = new Map<string, number>()
  const marked = new Marked({
    gfm: true,
    renderer: {
      heading({ tokens, depth, text }) {
        const baseAnchor = anchorOf(text)
        const occurrence = (anchorCounts.get(baseAnchor) ?? 0) + 1
        anchorCounts.set(baseAnchor, occurrence)
        const id = occurrence === 1 ? baseAnchor : `${baseAnchor}-${occurrence}`
        return `<h${depth} id="${id}">${this.parser.parseInline(tokens)}</h${depth}>\n`
      },
    },
  })
  return marked.parse(source, { async: false })
}
