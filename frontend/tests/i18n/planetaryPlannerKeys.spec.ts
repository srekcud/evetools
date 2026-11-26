import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'

function leafKeys(node: unknown, prefix = ''): string[] {
  if (typeof node !== 'object' || node === null) return [prefix]
  return Object.entries(node).flatMap(([key, child]) => leafKeys(child, prefix === '' ? key : `${prefix}.${key}`))
}

function plannerOf(locale: { pi: object }): Record<string, unknown> | undefined {
  return (locale.pi as Record<string, Record<string, unknown> | undefined>).planner
}

describe('planetary planner labels', () => {
  it('has the same keys in English and French, including the empty catalog error', () => {
    const english = plannerOf(en)
    const french = plannerOf(fr)

    expect(english).toBeDefined()
    expect(french).toBeDefined()
    expect(leafKeys(french).sort()).toEqual(leafKeys(english).sort())
    expect(leafKeys(english)).toContain('errors.emptyCatalog')
  })

  it('words the planets table, its totals, its shortfall banner, its slots bar and the account error in both languages', () => {
    const planner = (locale: { pi: object }) => plannerOf(locale) as Record<string, Record<string, unknown> | undefined>

    for (const locale of [en, fr]) {
      const planets = planner(locale).planets
      expect(planets).toBeDefined()
      for (const section of ['total', 'shortfall', 'slots']) {
        expect(typeof (planets as Record<string, unknown>)[section]).toBe('object')
      }
      expect(typeof (planner(locale).errors as Record<string, unknown>).account).toBe('string')
    }
    expect(leafKeys(planner(fr).planets).sort()).toEqual(leafKeys(planner(en).planets).sort())
  })

  it('words the what to produce ranking in both languages: base, share, four tiers, twelve columns, statuses and the market error', () => {
    const planner = (locale: { pi: object }) => plannerOf(locale) as Record<string, Record<string, unknown> | undefined>

    for (const locale of [en, fr]) {
      const ranking = planner(locale).ranking as Record<string, unknown> | undefined
      expect(ranking).toBeDefined()
      for (const section of ['base', 'share', 'tiers', 'columns', 'status']) {
        expect(typeof (ranking as Record<string, unknown>)[section]).toBe('object')
      }
      expect(leafKeys((ranking as Record<string, unknown>).tiers)).toHaveLength(4)
      expect(leafKeys((ranking as Record<string, unknown>).columns)).toHaveLength(12)
      expect(typeof (planner(locale).errors as Record<string, unknown>).market).toBe('string')
    }
    expect(leafKeys(planner(fr).ranking).sort()).toEqual(leafKeys(planner(en).ranking).sort())
  })

  it('words My templates in both languages: title, empty state, eight columns, three actions, confirmations and the three template errors', () => {
    const planner = (locale: { pi: object }) => plannerOf(locale) as Record<string, Record<string, unknown> | undefined>

    for (const locale of [en, fr]) {
      const templates = planner(locale).templates as Record<string, unknown> | undefined
      expect(templates).toBeDefined()
      for (const section of ['columns', 'actions', 'confirm']) {
        expect(typeof (templates as Record<string, unknown>)[section]).toBe('object')
      }
      for (const text of ['title', 'empty', 'save', 'missing']) {
        expect(typeof (templates as Record<string, unknown>)[text]).toBe('string')
      }
      expect(leafKeys((templates as Record<string, unknown>).columns)).toHaveLength(8)
      expect(leafKeys((templates as Record<string, unknown>).actions)).toHaveLength(3)
      for (const error of ['templatesRead', 'templateSave', 'templateDelete']) {
        expect(typeof (planner(locale).errors as Record<string, unknown>)[error]).toBe('string')
      }
    }
    expect(leafKeys(planner(fr).templates).sort()).toEqual(leafKeys(planner(en).templates).sort())
    expect((planner(fr).templates as Record<string, unknown>).title).not.toBe((planner(en).templates as Record<string, unknown>).title)
  })

  it('words following a plan in both languages: three actions, the default name, the banner, four gaps, the factories box, the revenue line and the follow error', () => {
    const planner = (locale: { pi: object }) => plannerOf(locale) as Record<string, Record<string, unknown> | undefined>
    const leafAt = (locale: { pi: object }, path: string): unknown => path.split('.').reduce<unknown>((node, key) => (typeof node === 'object' && node !== null ? (node as Record<string, unknown>)[key] : undefined), planner(locale).follow)

    for (const locale of [en, fr]) {
      expect(planner(locale).follow).toBeDefined()
      for (const path of ['actions.followPlans', 'actions.follow', 'actions.unfollow', 'defaultName', 'banner.title', 'banner.open', 'banner.missingRecipe', 'gap.missing', 'gap.free', 'gap.ok', 'gap.unknown', 'factories.title', 'revenue.title', 'revenue.openPlanner']) {
        expect(typeof leafAt(locale, path), path).toBe('string')
      }
      expect(typeof (planner(locale).errors as Record<string, unknown>).templateFollow).toBe('string')
      expect(leafAt(locale, 'defaultName')).toContain('{date}')
      expect(leafAt(locale, 'gap.missing')).toContain('{n}')
      expect(leafAt(locale, 'gap.free')).toContain('{n}')
    }
    expect(leafKeys(planner(fr).follow).sort()).toEqual(leafKeys(planner(en).follow).sort())
    for (const path of ['actions.followPlans', 'banner.title', 'banner.open', 'factories.title', 'revenue.title']) {
      expect(leafAt(fr, path), path).not.toBe(leafAt(en, path))
    }
  })

  it('names the planner tab in both languages and words it differently in French', () => {
    const tabs = (locale: { pi: { tabs: object } }) => (locale.pi.tabs as Record<string, string | undefined>).planner

    expect(typeof tabs(en)).toBe('string')
    expect(typeof tabs(fr)).toBe('string')
    expect(tabs(fr)).not.toBe(tabs(en))
  })
})
