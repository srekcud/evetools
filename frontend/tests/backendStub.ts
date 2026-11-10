export interface BackendCall {
  method: string
  url: string
  body: unknown
}

export type BackendAnswer = unknown | ((call: BackendCall) => unknown)

export class FailedAnswer {
  constructor(readonly status: number = 500) {}
}

function bodyOf(init?: RequestInit): unknown {
  return typeof init?.body === 'string' ? JSON.parse(init.body) : undefined
}

export function stubBackend(answers: Record<string, BackendAnswer>): BackendCall[] {
  const calls: BackendCall[] = []
  vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
    const call: BackendCall = { method: init?.method?.toUpperCase() ?? 'GET', url: String(input), body: bodyOf(init) }
    calls.push(call)
    const key = `${call.method} ${call.url}`
    if (!(key in answers)) return new Promise<Response>(() => {})
    const answer = answers[key]
    const value = typeof answer === 'function' ? (answer as (call: BackendCall) => unknown)(call) : answer
    if (value instanceof FailedAnswer) return Promise.resolve(new Response('{}', { status: value.status }))
    return Promise.resolve(new Response(JSON.stringify(value ?? null), { status: 200, headers: { 'Content-Type': 'application/json' } }))
  }))
  return calls
}

export function callsTo(calls: BackendCall[], method: string, url: string): BackendCall[] {
  return calls.filter(call => call.method === method && call.url === url)
}
