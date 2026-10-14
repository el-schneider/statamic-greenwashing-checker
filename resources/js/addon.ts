import CheckerPanel from './CheckerPanel.vue'
import { registerBard } from './bard'
import { publishGuard } from './store'
import ScanUtility from './ScanUtility.vue'
import './style.css'

Statamic.booting(() => {
    Statamic.$inertia.register('GreenwashingScan', ScanUtility)
    Statamic.$components.register('greenwashing_checker-fieldtype', CheckerPanel)
    registerBard()
    Statamic.$hooks.on('entry.saved', (resolve, _reject, payload) => {
        publishGuard.saved?.(payload)
        resolve()
    })
    for (const event of ['saving', 'publishing'] as const) {
        Statamic.$hooks.on(`entry.${event}`, (resolve, reject, payload) => {
            if (!publishGuard.current) return resolve()
            void publishGuard.current(event, payload).then(resolve, reject)
        })
    }
})
