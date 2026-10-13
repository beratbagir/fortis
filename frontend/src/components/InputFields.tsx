import type { FlowInput } from '../api'

interface Props {
  inputs: FlowInput[]
  values: Record<string, unknown>
  onChange: (values: Record<string, unknown>) => void
}

/** Akış ve onay girdileri için basit form alanları. Tipleri Kestra ayrıca doğrular. */
export function InputFields({ inputs, values, onChange }: Props) {
  const set = (id: string, value: unknown) => onChange({ ...values, [id]: value })

  return (
    <div className="space-y-2">
      {inputs.map((input) => {
        const label = (
          <span className="text-xs font-medium text-ink-2">
            {input.description ?? input.id}
            {input.required && <span className="text-bad"> *</span>}
          </span>
        )
        const value = values[input.id]

        if (input.type === 'BOOL' || input.type === 'BOOLEAN') {
          return (
            <label key={input.id} className="flex items-center gap-2">
              <input type="checkbox" checked={Boolean(value)} onChange={(e) => set(input.id, e.target.checked)} />
              {label}
            </label>
          )
        }

        if (input.values && input.values.length > 0) {
          return (
            <label key={input.id} className="block">
              {label}
              <select
                value={String(value ?? '')}
                onChange={(e) => set(input.id, e.target.value)}
                className="mt-0.5 block w-full rounded-md border border-line-strong px-2 py-1 text-sm"
              >
                <option value="">Seçin</option>
                {input.values.map((option) => (
                  <option key={option} value={option}>
                    {option}
                  </option>
                ))}
              </select>
            </label>
          )
        }

        return (
          <label key={input.id} className="block">
            {label}
            <input
              type={['INT', 'FLOAT'].includes(input.type) ? 'number' : 'text'}
              value={String(value ?? '')}
              placeholder={input.defaults != null ? String(input.defaults) : input.type}
              onChange={(e) => set(input.id, e.target.value)}
              className="mt-0.5 block w-full rounded-md border border-line-strong px-2 py-1 text-sm"
            />
          </label>
        )
      })}
    </div>
  )
}
