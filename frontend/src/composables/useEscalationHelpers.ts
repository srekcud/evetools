import { useI18n } from 'vue-i18n'
import type { Escalation } from '@/stores/escalation'
import type { EscalationTimerInfo } from '@/composables/useEscalationTimers'

type ToastFn = (message: string, type: 'success' | 'warning' | 'error') => void

export function useEscalationHelpers(
  getTimerInfo: (expiresAt: string) => EscalationTimerInfo,
  showToast: ToastFn,
) {
  const { t } = useI18n()

  // ========== Security Status Helpers ==========

  function secStatusColor(sec: number): string {
    if (sec >= 0.9) return 'text-emerald-400'
    if (sec >= 0.5) return 'text-green-400'
    if (sec > 0.0) return 'text-amber-400'
    return 'text-red-400'
  }

  function secBadgeClasses(sec: number): string {
    if (sec >= 0.9) return 'bg-emerald-500/20 text-emerald-400'
    if (sec >= 0.5) return 'bg-green-500/20 text-green-400'
    if (sec > 0.0) return 'bg-amber-500/20 text-amber-400'
    return 'bg-red-500/20 text-red-400'
  }

  // ========== Visibility Helpers ==========

  function visibilityBadgeClasses(vis: string): string {
    if (vis === 'perso') return 'bg-slate-500/20 text-slate-400 hover:ring-slate-400/30'
    if (vis === 'corp') return 'bg-indigo-500/20 text-indigo-400 hover:ring-indigo-400/30'
    if (vis === 'alliance') return 'bg-blue-500/20 text-blue-400 hover:ring-blue-400/30'
    return 'bg-purple-500/20 text-purple-400 hover:ring-purple-400/30'
  }

  function visibilityLabel(vis: string): string {
    if (vis === 'corp') return t('escalations.visibility.corp')
    if (vis === 'alliance') return t('escalations.visibility.alliance')
    if (vis === 'public') return t('escalations.visibility.public')
    return ''
  }

  function visibilityTitle(vis: string): string {
    if (vis === 'perso') return t('escalations.visibility.perso')
    if (vis === 'corp') return t('escalations.visibility.corp')
    if (vis === 'alliance') return t('escalations.visibility.alliance')
    return t('escalations.visibility.public')
  }

  function visibilityDescription(vis: string): string {
    if (vis === 'perso') return t('escalations.visibility.persoDesc')
    if (vis === 'corp') return t('escalations.visibility.corpDesc')
    if (vis === 'alliance') return t('escalations.visibility.allianceDesc')
    return t('escalations.visibility.publicDesc')
  }

  // ========== Price Display ==========

  function priceColor(escalation: Escalation): string {
    const timer = getTimerInfo(escalation.expiresAt)
    if (escalation.saleStatus === 'vendu') return 'text-emerald-400'
    if (timer.zone === 'danger') return 'text-red-400'
    if (timer.zone === 'warning') return 'text-amber-400'
    return 'text-slate-200'
  }

  function priceSubColor(escalation: Escalation): string {
    const timer = getTimerInfo(escalation.expiresAt)
    if (escalation.saleStatus === 'vendu') return 'text-emerald-500/60'
    if (timer.zone === 'danger') return 'text-red-500/60'
    if (timer.zone === 'warning') return 'text-amber-500/60'
    return 'text-slate-500'
  }

  // ========== Share Helpers ==========

  function shouldShowShareButtons(escalation: Escalation): boolean {
    return (
      escalation.isOwner &&
      escalation.visibility !== 'perso' &&
      escalation.saleStatus === 'envente'
    )
  }

  function shareWts(escalation: Escalation, nowValue: number): void {
    if (escalation.bmStatus === 'nouveau') {
      showToast(t('escalations.cannotShareNoBm'), 'warning')
      return
    }

    const remaining = new Date(escalation.expiresAt).getTime() - nowValue
    const hours = Math.max(0, Math.round(remaining / 3600000))

    const text = `WTS ${escalation.type} @ ${escalation.solarSystemName} ${hours}h ${escalation.price}m`
    navigator.clipboard.writeText(text).then(() => {
      showToast(t('escalations.wtsCopied'), 'success')
    }).catch(() => {
      showToast(t('common.errors.copyFailed'), 'error')
    })
  }

  function shareDiscord(escalation: Escalation): void {
    if (escalation.bmStatus === 'nouveau') {
      showToast(t('escalations.cannotShareNoBm'), 'warning')
      return
    }

    const expiresUnix = Math.floor(new Date(escalation.expiresAt).getTime() / 1000)

    const text = [
      `**WTS ${escalation.type}**`,
      `> Systeme : **${escalation.solarSystemName}** (${escalation.securityStatus.toFixed(1)}) | Expire <t:${expiresUnix}:R> | Prix : **${escalation.price}M ISK**`,
      `> Contact : ${escalation.characterName}`,
      `Partag\u00e9 avec [Evetools](<https://evetools.srekcud.be/escalations>)`,
    ].join('\n')

    navigator.clipboard.writeText(text).then(() => {
      showToast(t('escalations.discordCopied'), 'success')
    }).catch(() => {
      showToast(t('common.errors.copyFailed'), 'error')
    })
  }

  function shareAllWts(shareableEscalations: Escalation[], nowValue: number): void {
    if (shareableEscalations.length === 0) {
      showToast(t('escalations.noShareable'), 'warning')
      return
    }

    const lines = shareableEscalations.map(e => {
      const remaining = new Date(e.expiresAt).getTime() - nowValue
      const hours = Math.max(0, Math.round(remaining / 3600000))
      return `WTS ${e.type} @ ${e.solarSystemName} ${hours}h ${e.price}m`
    })

    navigator.clipboard.writeText(lines.join('\n')).then(() => {
      showToast(t('escalations.copiedWts', { count: shareableEscalations.length }), 'success')
    }).catch(() => {
      showToast(t('common.errors.copyFailed'), 'error')
    })
  }

  function shareAllDiscord(shareableEscalations: Escalation[]): void {
    if (shareableEscalations.length === 0) {
      showToast(t('escalations.noShareable'), 'warning')
      return
    }

    const blocks = shareableEscalations.map(e => {
      const expiresUnix = Math.floor(new Date(e.expiresAt).getTime() / 1000)
      return [
        `**WTS ${e.type}**`,
        `> Systeme : **${e.solarSystemName}** (${e.securityStatus.toFixed(1)}) | Expire <t:${expiresUnix}:R> | Prix : **${e.price}M ISK**`,
        `> Contact : ${e.characterName}`,
      ].join('\n')
    })

    const text = blocks.join('\n\n') + `\n\nPartag\u00e9 avec [Evetools](<https://evetools.srekcud.be/escalations>)`

    navigator.clipboard.writeText(text).then(() => {
      showToast(t('escalations.copiedDiscord', { count: shareableEscalations.length }), 'success')
    }).catch(() => {
      showToast(t('common.errors.copyFailed'), 'error')
    })
  }

  return {
    secStatusColor,
    secBadgeClasses,
    visibilityBadgeClasses,
    visibilityLabel,
    visibilityTitle,
    visibilityDescription,
    priceColor,
    priceSubColor,
    shouldShowShareButtons,
    shareWts,
    shareDiscord,
    shareAllWts,
    shareAllDiscord,
  }
}
