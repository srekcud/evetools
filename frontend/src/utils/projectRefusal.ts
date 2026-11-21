type Translate = (key: string, values: Record<string, string>) => string

interface KnownRefusal {
  pattern: RegExp
  key: string
  parameters: readonly string[]
}

const KNOWN_REFUSALS: readonly KnownRefusal[] = [
  {
    pattern: /^The line "(.+)" carries a linked job, a purchase or a contribution and cannot be removed from the plan\.$/,
    key: 'groupIndustry.refusals.workedLineDropped',
    parameters: ['line'],
  },
  {
    pattern: /^The line "(.+)" has already received (\d+), more than the (\d+) the plan now needs\.$/,
    key: 'groupIndustry.refusals.lineOverReceived',
    parameters: ['line', 'received', 'needed'],
  },
  {
    pattern: /^The line "(.+)" has (\d+) runs linked to ESI jobs, more than the (\d+) the plan now needs\.$/,
    key: 'groupIndustry.refusals.lineOverLinked',
    parameters: ['line', 'runs', 'needed'],
  },
  {
    pattern: /^The line "(.+)" has (\d+) runs marked, more than the (\d+) the plan now needs\.$/,
    key: 'groupIndustry.refusals.lineOverMarked',
    parameters: ['line', 'runs', 'needed'],
  },
  {
    pattern: /^The production plan has (\d+) BOM lines, above the limit of (\d+) BOM lines per project\.$/,
    key: 'groupIndustry.refusals.tooManyLines',
    parameters: ['count', 'limit'],
  },
  {
    pattern: /^The BOM of a completed project cannot be rebuilt\.$/,
    key: 'groupIndustry.refusals.projectCompleted',
    parameters: [],
  },
  {
    pattern: /^The (?:structure policy|blacklist) of a completed project cannot be changed$/,
    key: 'groupIndustry.refusals.projectCompleted',
    parameters: [],
  },
  {
    pattern: /^The blacklist of a project can only be changed while it is a draft$/,
    key: 'groupIndustry.refusals.blacklistDraftOnly',
    parameters: [],
  },
]

export function projectRefusalMessage(serverMessage: string, t: Translate): string {
  for (const refusal of KNOWN_REFUSALS) {
    const match = refusal.pattern.exec(serverMessage)
    if (match === null) continue
    const values = Object.fromEntries(refusal.parameters.map((name, index) => [name, match[index + 1]]))
    return t(refusal.key, values)
  }
  return serverMessage
}
