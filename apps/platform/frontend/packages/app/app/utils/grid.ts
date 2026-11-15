/** Columns of the dashboard grid. The view computes the row height. */
export const COLUMNS = 12

/** This many rows are searched when looking for a free cell. */
const MAX_ROWS = 40

export interface Placement {
  x: number
  y: number
  w: number
  h: number
}

export interface Tile extends Placement {
  id: number
  type: string
  settings: Record<string, unknown>
}

export function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value))
}

/** Two areas overlap when they intersect in both directions. */
export function overlaps(a: Placement, b: Placement): boolean {
  return a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y
}

/**
 * Does the placement fit into the grid without touching another tile?
 *
 * Overlaps are prevented instead of resolved: a grid that rearranges itself
 * on drop surprises everyone watching.
 */
export function fits(placement: Placement, tiles: Tile[], exceptId?: number, columns = COLUMNS): boolean {
  if (placement.x < 0 || placement.y < 0 || placement.x + placement.w > columns) {
    return false
  }

  return !tiles.some(tile => tile.id !== exceptId && overlaps(placement, tile))
}

/** The first free cell from the top left — null when nothing fits anymore. */
export function freeCell(tiles: Tile[], w: number, h: number, columns = COLUMNS): Placement | null {
  for (let y = 0; y < MAX_ROWS; y++) {
    for (let x = 0; x + w <= columns; x++) {
      const placement = { x, y, w, h }

      if (fits(placement, tiles, undefined, columns)) {
        return placement
      }
    }
  }

  return null
}

/**
 * An offset in pixels becomes an offset in grid cells.
 *
 * Rounded, not truncated: whoever drags a tile by half a column means the
 * next one — with truncation it would stay put until the full column and
 * feel sluggish.
 */
export function cellOffset(pixel: number, cellWidth: number): number {
  return cellWidth <= 0 ? 0 : Math.round(pixel / cellWidth)
}

/**
 * The placement after a drag, kept within the grid.
 *
 * `rowLimit` is the board's height: dragging past the bottom edge added a
 * row, and every row on screen shrank with it.
 */
export function shifted(placement: Placement, dx: number, dy: number, rowLimit = Infinity): Placement {
  return {
    ...placement,
    x: clamp(placement.x + dx, 0, COLUMNS - placement.w),
    y: clamp(placement.y + dy, 0, Math.max(0, rowLimit - placement.h)),
  }
}

/**
 * The placement after dragging the handle; at least one cell in size and
 * no further than the board's edges, so "full height" is where it stops.
 */
export function resized(placement: Placement, dw: number, dh: number, rowLimit = Infinity): Placement {
  return {
    ...placement,
    w: clamp(placement.w + dw, 1, COLUMNS - placement.x),
    h: clamp(placement.h + dh, 1, Math.max(1, rowLimit - placement.y)),
  }
}

export interface Trade {
  /** Where the dragged tile lands: on the other tile's cell. */
  moved: Placement
  /** The other tile, moved to where the dragged one came from. */
  id: number
  placement: Placement
}

/**
 * Dropped onto exactly one other tile, the two trade places: the dragged
 * tile takes the other's cell, the other the dragged one's origin, each
 * keeping its size. Null when that does not fit — then nothing moves.
 */
export function traded(origin: Placement, drop: Placement, tiles: Tile[], id: number, rowLimit = Infinity): Trade | null {
  const hit = tiles.filter(tile => tile.id !== id && overlaps(drop, tile))

  if (hit.length !== 1) {
    return null
  }

  const other = hit[0]!
  const moved = { ...drop, x: other.x, y: other.y }
  const placement = { x: origin.x, y: origin.y, w: other.w, h: other.h }
  const rest = tiles.filter(tile => tile.id !== id && tile.id !== other.id)
  const inside = (p: Placement) => p.y + p.h <= rowLimit && fits(p, rest)

  return inside(moved) && inside(placement) && !overlaps(moved, placement)
    ? { moved, id: other.id, placement }
    : null
}

/** How many rows the grid needs so everything fits. */
export function rows(tiles: Tile[], atLeast = 6): number {
  return Math.max(atLeast, ...tiles.map(tile => tile.y + tile.h))
}

/** Below phone width the tiles stack in reading order: row by row, left to right. */
export function stacked<T extends Placement>(tiles: T[]): T[] {
  return [...tiles].sort((a, b) => a.y - b.y || a.x - b.x)
}

/** Columns of the grid between phone and landscape, e.g. a tablet held upright. */
export const HALF_COLUMNS = 6

/**
 * The grid for half the width: every tile keeps its size in columns, so it
 * is twice as wide as in landscape, and they are set anew in reading order.
 * Display only — the stored placement stays the landscape one.
 */
export function reflowed<T extends Tile>(tiles: T[], columns = HALF_COLUMNS): T[] {
  const placed: T[] = []

  for (const tile of stacked(tiles)) {
    const w = Math.min(tile.w, columns)
    const cell = freeCell(placed, w, tile.h, columns)
      ?? { x: 0, y: rows(placed, 0), w, h: tile.h }

    placed.push({ ...tile, ...cell })
  }

  return placed
}
