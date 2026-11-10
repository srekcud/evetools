export type BridgeCardStatus = 'loading' | 'loaded' | 'empty' | 'unknown'

export function bridgeCardStatus(source: { isSettled: boolean, hasFailed: boolean, itemCount: number }): BridgeCardStatus {
  if (!source.isSettled) return 'loading'
  if (source.hasFailed) return 'unknown'
  return source.itemCount === 0 ? 'empty' : 'loaded'
}
