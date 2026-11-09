import { computed, type ComputedRef } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MiningCompression, MiningCompressionStatus, MiningCompressorKind } from '@/stores/miningOperation'

export interface MiningCompressionBadge {
  text: string
  classes: string
  hint: string
}

export interface MiningCompressionDisplay {
  badges: ComputedRef<Map<number, MiningCompressionBadge>>
  banners: ComputedRef<string[]>
}

export function useMiningCompression(source: () => MiningCompression | null | undefined): MiningCompressionDisplay {
  const { t, locale } = useI18n()

  function kindsText(kinds: MiningCompressorKind[]): string {
    return kinds.map(kind => t(`miningOperation.boostFit.kind.${kind}`).toLocaleLowerCase(locale.value)).join(', ')
  }

  function badgeOf(status: MiningCompressionStatus): MiningCompressionBadge {
    const hint = t(`miningOperation.boostFit.badgeHint.${status}`)
    if (status === 'on_site') return { text: t('miningOperation.boostFit.badge.onSite'), classes: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300', hint }
    if (status === 'unknown_ore_kind') return { text: t('miningOperation.boostFit.badge.unknown'), classes: 'border-slate-600 text-slate-400', hint }
    return { text: t('miningOperation.boostFit.badge.none'), classes: 'border-amber-500/40 bg-amber-500/10 text-amber-300', hint }
  }

  const badges = computed(() => {
    const result = new Map<number, MiningCompressionBadge>()
    const compression = source()
    if (compression === null || compression === undefined || compression.boostShipTypeId === null || compression.boostShipTypeId === undefined) return result
    for (const type of compression.types) result.set(type.typeId, badgeOf(type.status))
    return result
  })

  const banners = computed(() => {
    const compression = source()
    if (compression === null || compression === undefined) return []
    const { gaps, boostShipName } = compression
    const lines: string[] = []
    if (gaps.noHull.length > 0) lines.push(t('miningOperation.boostFit.noHullBanner', { ship: boostShipName, kinds: kindsText(gaps.noHull) }))
    if (gaps.notMounted.length > 0) lines.push(t('miningOperation.boostFit.notMountedBanner', { kinds: kindsText(gaps.notMounted) }))
    return lines
  })

  return { badges, banners }
}
