const sources = import.meta.glob('../../src/**/*.{ts,vue}', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

const API_CALL_WITH_URL_LITERAL = /\b(?:apiRequest|authFetch|fetch)(?:<[^()]*>)?\(\s*(['"`])([^'"`]*)\1/g
const REMOVED_API_ROUTE = /group-industry|\/steps\b|\/projects\/[^/]*\/(?:shopping-list|cost-estimation|bpc-kit|copy-costs)/

interface UrlLiteral {
  file: string
  url: string
}

function urlLiteralsOfApiCalls(): UrlLiteral[] {
  return Object.entries(sources).flatMap(([file, content]) =>
    Array.from(content.matchAll(API_CALL_WITH_URL_LITERAL), match => ({ file: file.replace('../../', ''), url: match[2] })),
  )
}

describe('front API calls', () => {
  it('target none of the routes removed with the group industry and step endpoints', () => {
    const urlLiterals = urlLiteralsOfApiCalls()

    expect(urlLiterals.map(({ url }) => url)).toContain('/industry/projects')
    expect(urlLiterals.filter(({ url }) => REMOVED_API_ROUTE.test(url))).toEqual([])
  })
})
