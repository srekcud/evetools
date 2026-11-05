import { useI18n } from 'vue-i18n'
import type { MiningSite } from '@/stores/miningSite'
import { scanAgeMinutesOf } from '@/views/MiningSite/miningSiteEstimate'
import { formatWhole } from '@/views/MiningOperation/miningOperationQuantity'
import { formatEveClock, formatEveDateTime } from '@/views/MiningOperation/miningOperationTime'

export const UNKNOWN_DASH = '–'

export function useMiningSiteFormat() {
  const { t, locale } = useI18n()

  function etaText(eta: string | null | undefined): string {
    return eta === null || eta === undefined ? t('miningSite.eta.unknown') : formatEveClock(eta)
  }

  function dateTimeText(moment: string): string {
    return formatEveDateTime(moment)
  }

  function wholeText(value: number): string {
    return formatWhole(value, locale.value)
  }

  function valueText(value: number | null | undefined): string {
    return value === null || value === undefined ? t('miningSite.value.unknown') : formatWhole(value, locale.value)
  }

  function authorText(authorName: string | null | undefined): string {
    return authorName === null || authorName === undefined ? t('miningSite.feed.unknownAuthor') : authorName
  }

  function lastScanText(site: MiningSite, now: number): string | null {
    if (site.lastScanNumber === null || site.lastScanNumber === undefined || site.lastScanAt === null || site.lastScanAt === undefined) return null
    const minutes = scanAgeMinutesOf(site.lastScanAt, now)
    const age = minutes < 1 ? t('miningSite.lastScan.justNow') : t('miningSite.lastScan.ago', { minutes })
    return t('miningSite.lastScan.text', { number: site.lastScanNumber, author: authorText(site.lastScanAuthorName), age })
  }

  return { etaText, dateTimeText, wholeText, valueText, authorText, lastScanText }
}
