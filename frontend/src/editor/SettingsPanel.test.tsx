import { act, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defs, sampleGraph } from '../test/fixtures'
import { BlockNode } from './nodes'
import { layoutGraph, type BlockData } from './layout'
import { SettingsPanel } from './SettingsPanel'
import type { Operation } from './types'
import { SETTINGS_DELAY_MS, useSettingsSaver } from './useSettingsSaver'

function Harness({ send }: { send: (ops: Operation[]) => void }) {
  const saver = useSettingsSaver(send)
  const node = sampleGraph().tasks[1].children![1]

  return <SettingsPanel mode="edit" node={node} def={defs.get('log')} isTrigger={false} errors={[]} onSchedule={saver.schedule} onDelete={() => {}} />
}

describe('blok ayarları formu', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('form değişikliği update_settings işlemi üretir; art arda değişikliklerde gecikmeli tek istek gider', () => {
    const send = vi.fn()
    render(<Harness send={send} />)
    const message = screen.getByLabelText(/Mesaj/)
    const title = screen.getByLabelText('Başlık')

    fireEvent.change(message, { target: { value: 'Rötar 1' } })
    act(() => void vi.advanceTimersByTime(300))
    fireEvent.change(message, { target: { value: 'Rötar 18' } })
    fireEvent.change(title, { target: { value: 'Rötarı logla' } })
    act(() => void vi.advanceTimersByTime(SETTINGS_DELAY_MS - 1))

    expect(send).not.toHaveBeenCalled()

    act(() => void vi.advanceTimersByTime(1))

    expect(send).toHaveBeenCalledTimes(1)
    expect(send).toHaveBeenCalledWith([{ op: 'update_settings', task_id: 'log_delay', settings: { message: 'Rötar 18', title: 'Rötarı logla' } }])
  })

  it('gelişmiş blok salt okunur gösterilir, form alanı yoktur', () => {
    const node = sampleGraph().tasks[2]
    render(<SettingsPanel mode="edit" node={node} def={defs.get('advanced')} isTrigger={false} errors={[]} onSchedule={() => {}} onDelete={() => {}} />)

    expect(screen.getByText(/Salt okunurdur/)).toBeInTheDocument()
    expect(screen.queryByLabelText('Başlık')).not.toBeInTheDocument()
  })

  it('422 cevabındaki task_id hatası ilgili blokta görünür', () => {
    const errors = [{ task_id: 'get_delays', message: "tasks[0]: APM'de böyle bir uç yok" }]
    const nodes = layoutGraph(sampleGraph(), { defs, errors })
    const data = nodes.find((n) => n.id === 'block:get_delays')!.data as BlockData

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    render(<BlockNode {...({ id: 'block:get_delays', data } as any)} />)

    const card = screen.getByRole('button', { name: /hata: tasks\[0\]: APM'de böyle bir uç yok/ })
    expect(card).toHaveTextContent('Hata')
    expect(card.style.border).toContain('2px solid')

    render(<SettingsPanel mode="edit" node={sampleGraph().tasks[0]} def={defs.get('data_apm')} isTrigger={false} errors={errors} onSchedule={() => {}} onDelete={() => {}} />)
    expect(screen.getByRole('alert')).toHaveTextContent("APM'de böyle bir uç yok")
  })
})
