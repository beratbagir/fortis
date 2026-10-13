import { createContext } from 'react'
import type { InsertPoint } from './types'

/** Tuval düğümlerinin editörle konuşması (seçme, ekleme noktası açma). */
export interface CanvasActions {
  select: (id: string | null) => void
  openInsert: (point: InsertPoint) => void
}

export const CanvasContext = createContext<CanvasActions>({ select: () => {}, openInsert: () => {} })
