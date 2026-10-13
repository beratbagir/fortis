import type { BlockDef, FlowGraph, GraphNode } from '../editor/types'

const node = (id: string, kind: string, extra: Partial<GraphNode> = {}): GraphNode => ({
  id,
  kind,
  label: kind,
  title: null,
  settings: {},
  readonly: false,
  raw_type: null,
  writes: false,
  ...extra,
})

export const defs = new Map<string, BlockDef>(
  (
    [
      ['trigger_schedule', { cron: { type: 'string', title: 'Zamanlama' } }],
      ['data_apm', { path: { type: 'string', title: 'Uç' } }],
      ['log', { message: { type: 'string', title: 'Mesaj', format: 'textarea' } }],
      ['notify_teams', { message: { type: 'string', title: 'Mesaj', format: 'textarea' } }],
      ['foreach', { values: { type: 'string', title: 'Liste' } }],
      ['if', { condition: { type: 'string', title: 'Koşul' } }],
      ['advanced', {}],
    ] as const
  ).map(([kind, properties]) => [
    kind,
    {
      kind,
      label: kind,
      group: 'Mantık',
      glyph: kind.slice(0, 2).toUpperCase(),
      tone: 'slate',
      id_prefix: kind,
      settings_schema: { type: 'object', required: kind === 'log' ? ['message'] : [], properties },
      summary_template: '',
      ...(kind === 'foreach' ? { container: true, branches: ['tasks'] } : {}),
      ...(kind === 'if' ? { container: true, branches: ['then', 'else'], branch_labels: { then: 'Doğruysa', else: 'Değilse' } } : {}),
    } as BlockDef,
  ]),
)

/** "Uzun rötar bildirimi": tetikleyici, veri, döngü (içinde koşul ve bildirim), gelişmiş adım. */
export function sampleGraph(): FlowGraph {
  return {
    flow_id: 'long-delay-notice',
    namespace: 'fortis.chat',
    description: null,
    triggers: [node('every_15_min', 'trigger_schedule', { settings: { cron: '*/15 * * * *' } })],
    tasks: [
      node('get_delays', 'data_apm', { settings: { path: '/api/legs', params: { min: '240' } } }),
      node('notify_each', 'foreach', {
        settings: { values: '{{ outputs.get_delays.body }}' },
        children: [
          node('is_long', 'if', {
            settings: { condition: '{{ x > 1 }}' },
            branches: { then: [node('send_mail', 'log', { settings: { message: 'Uzun' } })], else: [] },
          }),
          node('log_delay', 'log', { settings: { message: 'Rötar' } }),
        ],
      }),
      node('remember', 'advanced', { readonly: true, raw_type: 'io.kestra.plugin.core.kv.Set' }),
    ],
    errors: [],
    finally: [],
  }
}
