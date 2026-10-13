import Markdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import type { Components } from 'react-markdown'
import type { Role } from '../api'

/** Geniş tablolar balonun içinde yatay kayar. */
const MARKDOWN_COMPONENTS: Components = {
  table: ({ children }) => (
    <div className="table-scroll">
      <table>{children}</table>
    </div>
  ),
}

interface Props {
  role: Role
  text: string
  streaming?: boolean
}

export function MessageBubble({ role, text, streaming = false }: Props) {
  if (role === 'user') {
    return (
      <div className="flex justify-end">
        <div className="max-w-[80%] rounded-2xl rounded-br-md bg-ink px-4 py-2.5 whitespace-pre-wrap text-bg">
          {text}
        </div>
      </div>
    )
  }

  return (
    <div className="flex justify-start">
      <div className="prose-chat max-w-[85%] rounded-2xl rounded-bl-md border border-line bg-surface px-4 py-3">
        <Markdown remarkPlugins={[remarkGfm]} components={MARKDOWN_COMPONENTS}>
          {text}
        </Markdown>
        {streaming && <span className="ml-0.5 inline-block h-4 w-1.5 animate-pulse bg-faint align-middle" />}
      </div>
    </div>
  )
}

export function ThinkingIndicator({ label = 'Düşünüyor' }: { label?: string }) {
  return (
    <div className="flex justify-start">
      <div className="flex items-center gap-2 rounded-2xl rounded-bl-md border border-line bg-surface px-4 py-3 text-sm text-muted">
        <span className="flex gap-1">
          <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-faint [animation-delay:-0.3s]" />
          <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-faint [animation-delay:-0.15s]" />
          <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-faint" />
        </span>
        {label}
      </div>
    </div>
  )
}
