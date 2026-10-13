// Monaco CDN'den değil, paketten yüklenir (kurum ağında dış CDN'e bağımlılık olmasın). Sadece YAML dili.
import { loader } from '@monaco-editor/react'
import * as monaco from 'monaco-editor/editor'
import EditorWorker from 'monaco-editor/editor/editor.worker?worker'
import 'monaco-editor/languages/definitions/yaml/register'

self.MonacoEnvironment = { getWorker: () => new EditorWorker() }
loader.config({ monaco })
