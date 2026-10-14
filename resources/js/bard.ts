import { watch } from 'vue'
import { fieldPath, quoteRange } from './logic'
import { openFindings, overlay, state } from './store'

// Editors are supplied by Statamic, not created or bundled by this addon.
export const editors = new Map<string, any>()

export function registerBard(): void {
    Statamic.$bard.addExtension(({ bard, tiptap }) => {
        let path = fieldPath(bard)
        const { Plugin, PluginKey } = tiptap.pm.state
        const { Decoration, DecorationSet } = tiptap.pm.view
        const key = new PluginKey('greenwashingChecker')
        let stop: (() => void) | undefined
        let stopPath: (() => void) | undefined
        function decorations(doc: any) {
            return DecorationSet.create(
                doc,
                openFindings(path).flatMap((finding) => {
                    const range = finding.anchored ? quoteRange(doc, finding.quote) : null
                    return range
                        ? [
                              Decoration.inline(range.from, range.to, {
                                  class: `gwc-mark gwc-mark--${finding.severity}${overlay.popover?.path === path && overlay.popover.id === finding.id ? ' gwc-mark--active' : ''}`,
                                  'data-gwc-id': finding.id,
                              }),
                          ]
                        : []
                }),
            )
        }
        function open(view: any, target: EventTarget | null) {
            const mark = target instanceof Element ? target.closest<HTMLElement>('[data-gwc-id]') : null
            if (!mark) return false
            const finding = openFindings(path).find((f) => f.id === mark.dataset.gwdId)
            const range = finding ? quoteRange(view.state.doc, finding.quote) : null
            if (!finding || !range) return false
            const coords = view.coordsAtPos(range.from)
            overlay.popover = {
                path,
                id: finding.id,
                left: coords.left,
                top: coords.bottom + 8,
            }
            return true
        }
        return tiptap.core.Extension.create({
            name: 'greenwashingChecker',
            onCreate(this: any) {
                editors.set(path, this.editor)
                stopPath = watch(
                    () => fieldPath(bard),
                    (nextPath) => {
                        if (editors.get(path) === this.editor) editors.delete(path)
                        if (overlay.popover?.path === path) overlay.popover = null
                        path = nextPath
                        editors.set(path, this.editor)
                        this.editor.view.dispatch(this.editor.state.tr.setMeta(key, true))
                    },
                )
                stop = watch(
                    () => [state.result.units[path], overlay.popover?.path === path ? overlay.popover.id : null],
                    () => {
                        if (!this.editor.isDestroyed) this.editor.view.dispatch(this.editor.state.tr.setMeta(key, true))
                    },
                    { deep: true },
                )
                this.editor.view.dispatch(this.editor.state.tr.setMeta(key, true))
            },
            onDestroy(this: any) {
                stop?.()
                stopPath?.()
                if (editors.get(path) === this.editor) editors.delete(path)
                if (overlay.popover?.path === path) overlay.popover = null
            },
            addProseMirrorPlugins() {
                return [
                    new Plugin({
                        key,
                        state: {
                            init: (_: unknown, editorState: any) => decorations(editorState.doc),
                            apply: (tr: any, previous: any) =>
                                tr.getMeta(key) ? decorations(tr.doc) : previous.map(tr.mapping, tr.doc),
                        },
                        props: {
                            decorations: (editorState: any) => key.getState(editorState),
                            handleDOMEvents: {
                                click: (view: any, event: MouseEvent) => open(view, event.target),
                            },
                        },
                    }),
                ]
            },
        })
    })
}
