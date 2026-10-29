export interface Deferred<T> {
  promise: Promise<T>
  resolve: (value: T) => void
  reject: (reason: unknown) => void
}

export function deferred<T>(): Deferred<T> {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((onResolve, onReject) => {
    resolve = onResolve
    reject = onReject
  })
  return { promise, resolve, reject }
}

export type ApiOutcome = () => Promise<unknown>

export function respondWith(value: unknown): ApiOutcome {
  return () => Promise.resolve(value)
}

export function rejectWith(reason: Error): ApiOutcome {
  return () => Promise.reject(reason)
}

export function awaiting<T>(pending: Deferred<T>): ApiOutcome {
  return () => pending.promise
}

export function apiOutcomesByPath() {
  const queues = new Map<string, ApiOutcome[]>()
  return {
    respondTo(path: string, outcome: ApiOutcome): void {
      queues.set(path, [...(queues.get(path) ?? []), outcome])
    },
    answer(endpoint: string): Promise<unknown> | undefined {
      const outcome = queues.get(endpoint.split('?')[0])?.shift()
      return outcome?.()
    },
    clear(): void {
      queues.clear()
    },
  }
}
