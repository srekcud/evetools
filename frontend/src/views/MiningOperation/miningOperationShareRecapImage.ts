export const RECAP_IMAGE_WIDTH = 800
const PADDING = 32
const KICKER_FONT = '600 14px ui-monospace, monospace'
const TITLE_FONT = '700 24px ui-monospace, monospace'
const BODY_FONT = '400 18px ui-monospace, monospace'
const FOOTER_FONT = '400 14px ui-monospace, monospace'
const KICKER_LINE_HEIGHT = 24
const TITLE_LINE_HEIGHT = 34
const BODY_LINE_HEIGHT = 28
const FOOTER_LINE_HEIGHT = 22
const SECTION_GAP = 14
const BORDER_WIDTH = 2
const BACKGROUND_COLOR = '#0b1220'
const BORDER_COLOR = '#0891b2'
const KICKER_COLOR = '#22d3ee'
const TITLE_COLOR = '#a5f3fc'
const BODY_COLOR = '#e2e8f0'
const FOOTER_COLOR = '#94a3b8'

export type RecapLineStyle = 'kicker' | 'title' | 'body' | 'footer'

export interface RecapImageLine {
  text: string
  style: RecapLineStyle
}

const LINE_STYLES: Record<RecapLineStyle, { font: string; color: string; lineHeight: number }> = {
  kicker: { font: KICKER_FONT, color: KICKER_COLOR, lineHeight: KICKER_LINE_HEIGHT },
  title: { font: TITLE_FONT, color: TITLE_COLOR, lineHeight: TITLE_LINE_HEIGHT },
  body: { font: BODY_FONT, color: BODY_COLOR, lineHeight: BODY_LINE_HEIGHT },
  footer: { font: FOOTER_FONT, color: FOOTER_COLOR, lineHeight: FOOTER_LINE_HEIGHT },
}

interface WrappedLine {
  text: string
  style: RecapLineStyle
}

function breakLongWord(context: CanvasRenderingContext2D, word: string, maxWidth: number): string[] {
  const pieces: string[] = []
  let piece = ''
  for (const character of word) {
    if (piece !== '' && context.measureText(piece + character).width > maxWidth) {
      pieces.push(piece)
      piece = ''
    }
    piece += character
  }
  pieces.push(piece)
  return pieces
}

function wrap(context: CanvasRenderingContext2D, text: string, maxWidth: number): string[] {
  const rows: string[] = []
  let row = ''
  for (const word of text.split(' ')) {
    const candidate = row === '' ? word : `${row} ${word}`
    if (context.measureText(candidate).width <= maxWidth) {
      row = candidate
      continue
    }
    if (row !== '') rows.push(row)
    const pieces = breakLongWord(context, word, maxWidth)
    rows.push(...pieces.slice(0, -1))
    row = pieces[pieces.length - 1]
  }
  rows.push(row)
  return rows
}

function layout(context: CanvasRenderingContext2D, lines: RecapImageLine[]): WrappedLine[] {
  const maxWidth = RECAP_IMAGE_WIDTH - 2 * PADDING
  return lines.flatMap(line => {
    context.font = LINE_STYLES[line.style].font
    return wrap(context, line.text, maxWidth).map(text => ({ text, style: line.style }))
  })
}

function heightOf(rows: WrappedLine[]): number {
  return 2 * PADDING + rows.reduce((height, row) => height + LINE_STYLES[row.style].lineHeight, 0) + SECTION_GAP
}

export function drawRecapImage(canvas: HTMLCanvasElement, lines: RecapImageLine[]): void {
  const context = canvas.getContext('2d')
  if (context === null) throw new Error('Canvas 2D context is not available')
  canvas.width = RECAP_IMAGE_WIDTH
  const rows = layout(context, lines)
  canvas.height = heightOf(rows)
  context.fillStyle = BACKGROUND_COLOR
  context.fillRect(0, 0, canvas.width, canvas.height)
  context.strokeStyle = BORDER_COLOR
  context.lineWidth = BORDER_WIDTH
  context.strokeRect(BORDER_WIDTH / 2, BORDER_WIDTH / 2, canvas.width - BORDER_WIDTH, canvas.height - BORDER_WIDTH)
  context.textBaseline = 'top'
  let top = PADDING
  for (const row of rows) {
    const style = LINE_STYLES[row.style]
    context.font = style.font
    context.fillStyle = style.color
    context.fillText(row.text, PADDING, top)
    top += style.lineHeight
  }
}

export function canDrawRecapImage(): boolean {
  return document.createElement('canvas').getContext('2d') !== null
}

export function recapImageBlob(canvas: HTMLCanvasElement): Promise<Blob> {
  return new Promise((resolve, reject) => {
    canvas.toBlob(blob => (blob === null ? reject(new Error('The canvas could not be encoded as PNG')) : resolve(blob)), 'image/png')
  })
}
