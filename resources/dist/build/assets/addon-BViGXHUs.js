import{r as l,d as c,v as d,e as p,a as o,p as f,A as h,t as g,h as b,g as u,c as m,i as v}from"./logic-u1FA3fyo.js";const y=["text","textarea","markdown"],w={name:"GreenwashingCheckerPanelV5",inject:["storeName"],props:{value:{default:null},meta:{type:Object,required:!0},config:{type:Object,default:()=>({})},handle:{type:String,default:""},readOnly:{type:Boolean,default:!1}},data(){return{checker:v(),busy:!1,snapshot:"",controller:void 0,review:!1,reviewMode:"open",expanded:null,reviewReturnFocus:null}},computed:{fieldActions(){return[]},publishState(){const e=this;return e.$store.state.publish[e.storeName]},values(){return this.publishState.values},tabs(){return this.publishState.blueprint.tabs},site(){return this.publishState.site},norms(){const e=this;return e.meta.normsBySite[e.site]??{}},settings(){return Statamic.$config.get("greenwashingChecker")},setupState(){const e=this;return m(e.meta.collection,e.settings.configured)},openGroups(){const e=this;return u(e.checker.result,e.tabs,"open")},ignoredGroups(){const e=this;return u(e.checker.result,e.tabs,"ignored")},reviewGroups(){const e=this;return e.reviewMode==="ignored"?e.ignoredGroups:e.openGroups},openCount(){return this.openGroups.reduce((s,t)=>s+t.fields.reduce((n,r)=>n+r.findings.length,0),0)},ignoredCount(){return this.ignoredGroups.reduce((s,t)=>s+t.fields.reduce((n,r)=>n+r.findings.length,0),0)},statusText(){const e=this;if(e.checker.status==="completed"&&e.snapshot!==o(e.values))return __("greenwashing-checker::messages.statuses.changed");if(e.checker.status==="completed"){const s=[e.openCount?__n("greenwashing-checker::messages.statuses.findings",e.openCount):__("greenwashing-checker::messages.statuses.none")],t=b(e.checker.result).length;return t&&s.push(__n("greenwashing-checker::messages.high_count",t)),s.join(" · ")}return __("greenwashing-checker::messages.statuses."+e.checker.status)}},created(){const e=this;e.snapshot=o(e.values)},beforeDestroy(){var e;(e=this.controller)==null||e.abort()},methods:{t(e,s){return __("greenwashing-checker::messages."+e,s)},send(e){const s=this;s.checker=g(s.checker,e)},message(e){const s=this;if(!(e instanceof h))return console.error(e),s.t("errors.unexpected");const n="greenwashing-checker::messages.errors."+(e.status===403?"forbidden":e.status===404?"not_found":e.code==="validation"?"invalid_values":e.code),r=__(n);return r===n?e.message:r},async analyze(){var n;const e=this;if(e.setupState)return;(n=e.controller)==null||n.abort(),e.controller=new AbortController;const s=e.controller.signal;e.send({type:"start"});const t=e.checker.request;try{if(await new Promise(a=>setTimeout(a,151)),s.aborted)return;const r=JSON.parse(o(e.values));e.snapshot=o(r);const i=await l("POST",c("greenwashing-checker/analyze"),{reference:e.publishState.reference??null,collection:e.meta.collection,blueprint:e.publishState.blueprint.handle,site:e.site,values:r});if(s.aborted)return;if(i.status==="completed")e.send({type:"complete",request:t,result:i.result});else{const a=await f(i.poll,{signal:s});a.status==="completed"&&e.send({type:"complete",request:t,result:a.result})}}catch(r){s.aborted||e.send({type:"fail",request:t,error:e.message(r)})}},canApply(e){const s=this;if(s.readOnly||e.path.includes("."))return!1;const t=s.tabs.flatMap(n=>n.sections.flatMap(r=>r.fields)).find(n=>n.handle===e.path);return!!t&&y.includes(String(t.type))&&typeof d(s.values,e.path)=="string"},apply(e,s){const t=this,n=d(t.values,e.path),r=typeof n=="string"?p(n,e.quote,s):null;if(r===null){Statamic.$toast.error(t.t("errors.changed_text"));return}t.$store.dispatch(`publish/${t.storeName}/setFieldValue`,{handle:e.path,value:r});const i=t.modeKeys().indexOf(t.findingKey(e));t.send({type:"remove",path:e.path,id:e.id}),t.expanded===t.findingKey(e)&&(t.expanded=null),t.review&&t.afterTransport(()=>t.focusRow(i))},async dismiss(e,s=!0){const t=this;t.busy=!0;const n=t.checker.request;let r=-1;try{await l("POST",c("greenwashing-checker/dismiss"),{unit_hash:t.checker.result.units[e.path].hash,finding_id:e.id,dismissed:s,reference:t.publishState.reference??null,collection:t.meta.collection,site:t.site}),n===t.checker.request&&(r=t.modeKeys().indexOf(t.findingKey(e)),t.send({type:s?"dismiss":"restore",path:e.path,id:e.id}),t.expanded===t.findingKey(e)&&(t.expanded=null))}catch(i){Statamic.$toast.error(t.message(i))}finally{t.busy=!1,r!==-1&&t.review&&t.afterTransport(()=>t.focusRow(r))}},jump(e){if(e.includes("."))return;const s=document.getElementById("field_"+e)??document.querySelector(".publish-field__"+CSS.escape(e));if(!s)return;const t=s.closest(".form-group")??s;try{Statamic.$reveal.element(t)}catch{}t.scrollIntoView({behavior:"smooth",block:"center"});const n=s.matches('input, textarea, [contenteditable="true"]')?s:t.querySelector('textarea:not(.sr-only), [contenteditable="true"], input:not(.sr-only):not(.hidden):not([type="hidden"]):not([type="file"])');n==null||n.focus({preventScroll:!0})},findingKey(e){return e.path+":"+e.id},modeKeys(){const e=this;return e.reviewGroups.flatMap(s=>s.fields.flatMap(t=>t.findings.map(n=>e.findingKey(n))))},focusRow(e){var r;const t=this.$refs.reviewSheet,n=(t==null?void 0:t.querySelectorAll("[data-gwc-review-toggle]"))??[];if(n.length){n[Math.min(Math.max(e,0),n.length-1)].focus();return}(r=t==null?void 0:t.querySelector('[data-gwc-review-modes] [aria-pressed="true"]'))==null||r.focus()},afterTransport(e){const s=this;s.$nextTick(()=>s.$nextTick(e))},toggleFinding(e){const s=this,t=s.findingKey(e);s.expanded=s.expanded===t?null:t},openReview(){const e=this;e.reviewReturnFocus=document.activeElement instanceof HTMLElement?document.activeElement:null,e.reviewMode="open",e.review=!0,e.afterTransport(()=>{var s,t;(t=(s=e.$refs.reviewSheet)==null?void 0:s.querySelector("button"))==null||t.focus()})},closedReview(){const e=this;e.review=!1,e.expanded=null;const s=e.reviewReturnFocus;e.reviewReturnFocus=null,e.$nextTick(()=>{s!=null&&s.isConnected&&s.focus()})},reviewJump(e,s){const t=this;t.reviewReturnFocus=null,s(),t.jump(e)}},template:`
        <div class="gwc-v5">
        <section class="gwc-panel-v5" :aria-label="t('title')">
            <h3>{{ t('title') }}</h3>
            <template v-if="!setupState">
                <p role="status" aria-live="polite" class="text-sm">{{ statusText }}</p>
                <p v-if="checker.error" role="alert" class="text-sm" style="color: #c0392b">{{ checker.error }}</p>
                <div style="margin: 12px 0; display: flex; gap: 8px; flex-wrap: wrap">
                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        :disabled="checker.status === 'checking'"
                        @click="analyze"
                    >
                        {{ t('buttons.' + (checker.status === 'failed' ? 'retry' : 'check')) }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-sm"
                        :disabled="!openCount && !ignoredCount"
                        @click="openReview"
                    >
                        {{ t('buttons.review') }}
                    </button>
                </div>
            </template>
            <div v-else role="status">
                <p class="text-sm">{{ t(setupState) }}</p>
                <a
                    v-if="setupState === 'not_configured'"
                    href="https://github.com/el-schneider/statamic-greenwashing-checker#configuration"
                    target="_blank"
                    rel="noopener noreferrer"
                    >{{ t('buttons.documentation') }}</a
                >
            </div>
            <p class="help-block" style="margin-top: 12px">{{ t('disclaimer') }}</p>
        </section>
        <stack v-if="review" name="greenwashing-review" @closed="closedReview">
            <div
                ref="reviewSheet"
                slot-scope="{ close }"
                class="h-full overflow-auto bg-white dark:bg-dark-600 relative"
                role="dialog"
                aria-modal="true"
                :aria-label="t('title')"
            >
                <div class="bg-white dark:bg-dark-550 shadow px-4 py-2 flex items-center">
                    <div class="flex-1">
                        <h2 class="text-xl font-bold">{{ t('title') }}</h2>
                        <p role="status" aria-live="polite" class="text-sm text-gray-700 dark:text-dark-150">
                            {{ statusText }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="btn btn-sm"
                        :disabled="checker.status === 'checking'"
                        @click="analyze"
                    >
                        {{ t('buttons.' + (checker.status === 'failed' ? 'retry' : 'check')) }}
                    </button>
                    <button
                        type="button"
                        class="btn-close ltr:ml-2 rtl:mr-2"
                        @click="close"
                        :aria-label="t('buttons.close')"
                    >&times;</button>
                </div>
                <div class="p-4">
                    <p v-if="checker.error" role="alert" class="text-sm text-red-500">{{ checker.error }}</p>
                    <div data-gwc-review-modes class="btn-group" role="group" :aria-label="t('title')">
                        <button
                            type="button"
                            class="btn btn-sm"
                            :class="{ active: reviewMode === 'open' }"
                            :aria-pressed="reviewMode === 'open' ? 'true' : 'false'"
                            @click="reviewMode = 'open'"
                        >
                            {{ t('review_open') }} ({{ openCount }})
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm"
                            :class="{ active: reviewMode === 'ignored' }"
                            :aria-pressed="reviewMode === 'ignored' ? 'true' : 'false'"
                            @click="reviewMode = 'ignored'"
                        >
                            {{ t('review_ignored') }} ({{ ignoredCount }})
                        </button>
                    </div>
                <div v-for="tab in reviewGroups" :key="tab.handle" class="mt-4">
                    <h3 class="text-sm text-gray-800 dark:text-dark-150" style="font-weight: 600">{{ tab.display }}</h3>
                    <div v-for="field in tab.fields" :key="field.path" class="card overflow-hidden p-0 mt-2">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>{{ field.breadcrumb }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="finding in field.findings">
                                    <tr :key="'row-' + finding.id">
                                        <td>
                                            <button
                                                type="button"
                                                class="cursor-pointer"
                                                style="display: block; width: 100%; text-align: start; padding: 0"
                                                data-gwc-review-toggle
                                                :aria-expanded="expanded === findingKey(finding) ? 'true' : 'false'"
                                                :aria-controls="'gwc-review-' + finding.path + '-' + finding.id"
                                                @click="toggleFinding(finding)"
                                            >
                                                <span
                                                    :class="
                                                        'little-dot ' +
                                                        (finding.severity === 'high' ? 'bg-red-500' : 'bg-orange-light')
                                                    "
                                                    aria-hidden="true"
                                                ></span>
                                                {{ ' ' }}<span class="text-gray-800 dark:text-dark-150" style="font-weight: 600">{{
                                                    t('categories.' + finding.category)
                                                }}</span>
                                                <span class="sr-only">{{ t('severity.' + finding.severity) }}</span>
                                                <span
                                                    style="display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 2px"
                                                    >{{ finding.quote }}</span
                                                >
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="expanded === findingKey(finding)" :key="'detail-' + finding.id">
                                        <td :id="'gwc-review-' + finding.path + '-' + finding.id">
                                            <p class="help-block">{{ norms[finding.category] }}</p>
                                            <p
                                                class="text-sm text-gray-800 dark:text-dark-150 mt-2"
                                                style="text-align: start"
                                            >
                                                &ldquo;{{ finding.quote }}&rdquo;
                                            </p>
                                            <p class="text-sm mt-2">{{ finding.explanation }}</p>
                                            <p v-if="!finding.anchored" class="help-block">{{ t('unclear_position') }}</p>
                                            <div v-for="suggestion in finding.suggestions" :key="suggestion" class="mt-2">
                                                <p class="text-sm">
                                                    <span class="text-gray-800 dark:text-dark-150" style="font-weight: 600"
                                                        >{{ t('suggestion') }}:</span
                                                    >
                                                    {{ suggestion }}
                                                    <button
                                                        v-if="canApply(finding)"
                                                        type="button"
                                                        class="btn btn-xs"
                                                        :disabled="!finding.anchored || busy"
                                                        @click="apply(finding, suggestion)"
                                                    >
                                                        {{ t('buttons.apply') }}
                                                    </button>
                                                </p>
                                            </div>
                                            <div class="mt-2 flex">
                                                <button
                                                    v-if="reviewMode === 'ignored'"
                                                    type="button"
                                                    class="btn btn-sm"
                                                    :disabled="busy"
                                                    @click="dismiss(finding, false)"
                                                >
                                                    {{ t('buttons.restore') }}
                                                </button>
                                                <button
                                                    v-else
                                                    type="button"
                                                    class="btn btn-sm"
                                                    :disabled="busy"
                                                    @click="dismiss(finding, true)"
                                                >
                                                    {{ t('buttons.dismiss') }}
                                                </button>
                                                <button
                                                    v-if="!finding.path.includes('.')"
                                                    type="button"
                                                    class="btn btn-sm ltr:ml-2 rtl:mr-2"
                                                    @click="reviewJump(finding.path, close)"
                                                >
                                                    {{ t('buttons.jump') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div v-if="reviewMode === 'open' && !openCount" class="mt-4 text-center">
                    <p class="text-sm" style="font-weight: 600">{{ t('review_empty_open') }}</p>
                    <p class="help-block">{{ t('review_empty_help') }}</p>
                </div>
                <div v-if="reviewMode === 'ignored' && !ignoredCount" class="mt-4 text-center">
                    <p class="text-sm" style="font-weight: 600">{{ t('review_empty_ignored') }}</p>
                </div>
                </div>
                <div
                    class="p-4 border-t dark:border-dark-200 flex items-center justify-between bg-gray-200 dark:bg-dark-500"
                >
                    <p role="status" aria-live="polite" class="text-sm text-gray-700 dark:text-dark-150">
                        {{ statusText }}
                    </p>
                    <p class="text-sm text-gray-700 dark:text-dark-150 ltr:ml-2 rtl:mr-2">{{ t('disclaimer') }}</p>
                </div>
            </div>
        </stack>
        </div>
    `};Statamic.booting(()=>{Statamic.$components.register("greenwashing_checker-fieldtype",w)});
