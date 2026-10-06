export function venueLabel(key: string, structureName: string, t: (key: string) => string): string {
  switch (key) {
    case 'jitaSell':
      return 'Jita sell'
    case 'structureSell':
      return structureName
    case 'contractSell':
      return 'Contract'
    case 'structureBuy':
      return `${structureName} (${t('industry.margins.instantSellTag')})`
    default:
      return ''
  }
}
