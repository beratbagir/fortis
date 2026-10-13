import type { Operation, Settings } from './types'

/**
 * Bekleyen ayar değişiklikleri. Aynı bloğun art arda değişiklikleri tek update_settings işleminde birleşir;
 * gecikme (debounce) bitince tek istek gider.
 */
export class SettingsQueue {
  private pending = new Map<string, Operation>()

  push(op: Extract<Operation, { op: 'update_settings' | 'update_trigger' }>): void {
    const key = op.op === 'update_settings' ? `task:${op.task_id}` : `trigger:${op.trigger_id}`
    const current = this.pending.get(key) as typeof op | undefined
    const settings: Settings = { ...(current?.settings ?? {}), ...op.settings }
    this.pending.set(key, { ...op, settings })
  }

  take(): Operation[] {
    const ops = [...this.pending.values()]
    this.pending.clear()
    return ops
  }

  get size(): number {
    return this.pending.size
  }
}
