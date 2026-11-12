import { i18n } from '@/i18n'
import { useToast } from '@/composables/useToast'

export type CompanionOwner = 'mining' | 'group'

export type CompanionWindowKind = 'pip' | 'popup'

const holders = new Map<CompanionOwner, () => void>()

function otherOf(owner: CompanionOwner): CompanionOwner {
  return owner === 'mining' ? 'group' : 'mining'
}

export function otherOwnerHolding(owner: CompanionOwner): CompanionOwner | null {
  const other = otherOf(owner)
  return holders.has(other) ? other : null
}

export function claimCompanionWindow(
  owner: CompanionOwner,
  windowKind: CompanionWindowKind,
  heldByOther: CompanionOwner | null,
  onReplaced: () => void,
): CompanionOwner | null {
  const other = otherOf(owner)
  const replaced = windowKind === 'pip' ? holders.get(other) : undefined
  holders.set(owner, onReplaced)
  if (windowKind !== 'pip' || heldByOther === null) return null
  holders.delete(other)
  replaced?.()
  useToast().showToast(i18n.global.t(`companionWindow.replaced.${heldByOther}`), 'warning')
  return heldByOther
}

export function releaseCompanionWindow(owner: CompanionOwner): void {
  holders.delete(owner)
}
