import { readdirSync, readFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import { NodeTypes, type ElementNode, type TemplateChildNode } from '@vue/compiler-dom'
import { parse } from '@vue/compiler-sfc'

const SOURCE_ROOT = join(__dirname, '..', '..', 'src')
const PROJECT_FLOW_DIRECTORIES = ['components/group-industry', 'views/GroupIndustry']
const TRANSLATABLE_ATTRIBUTES = ['title', 'placeholder', 'aria-label', 'alt', 'label']
const WORD = /[A-Za-z]{3,}/
const ALLOWED_TEXTS = new Set(['BOM', 'BPC', 'ESI', 'ESI ✓', 'ISK', 'Jita', 'Ishtar', 'Jita 4-4', 'ItemName ME TE xRuns [*Copies]'])
const T_CALL = /\bt[ce]?\(/g
const TERNARY_BRANCH_LITERAL = /[?:]\s*'([^']*[A-Za-z]{3,}[^']*)'/g
const SCRIPT_LABEL_LITERAL = /^\s*(?:[a-zA-Z_]+):\s*[`'"]([A-Z][a-z][^`'"]*)[`'"]/
const SCRIPT_SENTENCE_LITERAL = /['"`]([A-Z][a-z]+(?: [A-Za-z(]+)+[.!]?)['"`]/
const DEVELOPER_ONLY_LINE = /throw new Error|^\s*import |console\./

function projectFlowFiles(): string[] {
  return PROJECT_FLOW_DIRECTORIES.flatMap(directory =>
    readdirSync(join(SOURCE_ROOT, directory))
      .filter(file => file.endsWith('.vue'))
      .map(file => join(SOURCE_ROOT, directory, file)),
  )
}

function withoutTranslationCalls(expression: string): string {
  let remaining = expression
  for (let match = T_CALL.exec(remaining); match !== null; match = T_CALL.exec(remaining)) {
    let depth = 1
    let cursor = match.index + match[0].length
    while (depth > 0 && cursor < remaining.length) {
      if (remaining[cursor] === '(') depth++
      if (remaining[cursor] === ')') depth--
      cursor++
    }
    remaining = remaining.slice(0, match.index) + remaining.slice(cursor)
    T_CALL.lastIndex = match.index
  }

  return remaining
}

function offendersOfNode(node: TemplateChildNode): string[] {
  if (node.type === NodeTypes.TEXT) {
    const text = node.content.replace(/\s+/g, ' ').trim()

    return WORD.test(text) && !ALLOWED_TEXTS.has(text) ? [text] : []
  }
  if (node.type === NodeTypes.INTERPOLATION && node.content.type === NodeTypes.SIMPLE_EXPRESSION) {
    const remaining = withoutTranslationCalls(node.content.content)

    return [...remaining.matchAll(TERNARY_BRANCH_LITERAL)].map(match => match[1]).filter(text => !ALLOWED_TEXTS.has(text))
  }
  if (node.type !== NodeTypes.ELEMENT) return []

  const element: ElementNode = node
  const attributeOffenders = element.props.flatMap((prop) => {
    if (prop.type !== NodeTypes.ATTRIBUTE || !TRANSLATABLE_ATTRIBUTES.includes(prop.name)) return []
    const value = prop.value?.content ?? ''

    return WORD.test(value) && !ALLOWED_TEXTS.has(value) ? [`@${prop.name}=${value}`] : []
  })

  return [...attributeOffenders, ...element.children.flatMap(offendersOfNode)]
}

function templateOffenders(source: string): string[] {
  const { descriptor } = parse(source)

  return descriptor.template?.ast?.children.flatMap(offendersOfNode) ?? []
}

function scriptOffenders(source: string): string[] {
  const { descriptor } = parse(source)
  const script = descriptor.scriptSetup?.content ?? descriptor.script?.content ?? ''

  return script.split('\n')
    .filter(line => !DEVELOPER_ONLY_LINE.test(line))
    .flatMap((line) => {
      const label = SCRIPT_LABEL_LITERAL.exec(line)
      const sentence = SCRIPT_SENTENCE_LITERAL.exec(line)

      return [label?.[1], sentence?.[1]].filter((text): text is string => text !== undefined && !ALLOWED_TEXTS.has(text))
    })
}

function offendersByFile(offendersOf: (source: string) => string[]): Record<string, string[]> {
  const entries = projectFlowFiles()
    .map(file => [relative(SOURCE_ROOT, file), offendersOf(readFileSync(file, 'utf8'))] as const)
    .filter(([, offenders]) => offenders.length > 0)

  return Object.fromEntries(entries)
}

describe('the project flow shows no hard-coded text', () => {
  it('has no untranslated text, title, placeholder or label in the templates', () => {
    expect(offendersByFile(templateOffenders)).toEqual({})
  })

  it('has no untranslated label, title or message literal in the scripts', () => {
    expect(offendersByFile(scriptOffenders)).toEqual({})
  })
})
