export function multibuyText(lines: readonly { typeName: string; quantity: number }[]): string {
  return lines.map(line => `${line.typeName}\t${line.quantity}`).join('\n')
}
