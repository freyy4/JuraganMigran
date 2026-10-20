import grapesjs, {type Component, type Editor} from 'grapesjs';

type Json = string | number | boolean | null | Json[] | {[key: string]: Json | undefined};
type Entity = {[key: string]: Json | undefined};
type Kind = 'block' | 'field' | 'step' | 'thank_you';
type Page = 'landing' | 'form' | 'thank_you';
type AddKind = 'add-block' | 'add-field';
interface Ref {kind: Kind; id: string}
interface Target extends Ref {after: boolean}
interface Schema {
    blocks: (Entity & {id: string; type: string})[];
    fields: (Entity & {key: string; type: string; label: string; step: string; required: boolean})[];
    steps: (Entity & {id: string; title: string})[];
    thank_you: Entity & {headline: string; text: string};
    [key: string]: Json | undefined;
}
interface Options {
    schema: Schema;
    editable: boolean;
    names: Record<string, string>;
    getSchema: () => Schema;
    onSelect: (kind: Kind, id: string) => void;
    onCommit: (kind: Kind, id: string, key: string, value: string) => void;
    onMove: (kind: Kind, id: string, target: Target) => void;
    onAdd: (kind: AddKind, id: string, target: Target) => void;
    onError: (message: string) => void;
}
interface Layer extends Ref {label: string; hidden: boolean; componentType: string}
const protectedFields = new Set(['name', 'whatsapp']);
const copy = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;
const keyOf = (ref: Ref): string => `${ref.kind}:${ref.id}`;
const escapeText = (value: unknown): string => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]!));

/** GrapesJS owns the component tree; Laravel's existing JSON remains the persistence contract. */
class FunnelEditorEngine {
    private readonly editor: Editor;
    private readonly components = new Map<string, Component>();
    private readonly pages = new Map<Page, Component>();
    private signature = '';
    private disposed = false;
    private selected: Ref | null = null;

    constructor(private readonly options: Options) {
        this.editor = grapesjs.init({
            headless: true,
            autorender: false,
            telemetry: false,
            storageManager: false,
            undoManager: false,
            noticeOnUnload: false,
            cssIcons: '',
            panels: {defaults: []},
            assetManager: {assets: []},
        });
        for (const kind of ['page', 'block', 'field', 'step', 'thank_you']) {
            this.editor.Components.addType(`jm-${kind}`, {model: {defaults: {
                tagName: kind === 'field' ? 'div' : 'section',
                editable: false,
                stylable: false,
                droppable: kind === 'page' || kind === 'step',
                draggable: kind !== 'page' && options.editable,
                removable: false,
                copyable: false,
                toolbar: [],
            }}});
        }
        const wrapper = this.editor.getWrapper();
        if (!wrapper) throw new Error('Komponen editor belum dapat disiapkan.');
        for (const page of ['landing', 'form', 'thank_you'] as const) {
            this.pages.set(page, wrapper.append({type:'jm-page', attributes:{'data-jm-page':page}})[0]!);
        }
        this.editor.Commands.add('jm:select', {run: (_editor, _sender, payload: {ref: Ref; notify: boolean}) => {
            const component = this.components.get(keyOf(payload.ref));
            if (!component) return false;
            this.editor.select(component);
            this.selected = {...payload.ref};
            if (payload.notify) options.onSelect(payload.ref.kind, payload.ref.id);
            return true;
        }});
        this.editor.Commands.add('jm:commit', {run: (_editor, _sender, payload: {ref: Ref; property: string; value: string}) => this.commitComponent(payload.ref, payload.property, payload.value)});
        this.editor.Commands.add('jm:move', {run: (_editor, _sender, payload: {ref: Ref; target: Target}) => this.moveComponent(payload.ref, payload.target)});
        this.editor.Commands.add('jm:add', {run: (_editor, _sender, payload: {kind: AddKind; id: string; target: Target}) => {
            if (!options.editable || !this.editor.Blocks.get(`${payload.kind}:${payload.id}`) || !this.components.has(keyOf(payload.target))) return false;
            options.onAdd(payload.kind, payload.id, payload.target);
            this.sync(options.getSchema());
            return true;
        }});
        this.sync(options.schema);
    }

    sync(schema: Schema): void {
        if (this.disposed) return;
        const signature = JSON.stringify(schema);
        if (signature === this.signature) return;
        const retained = new Set<string>();
        const reconcile = (parent: Component, ref: Ref, data: Entity, index: number): Component => {
            const key = keyOf(ref);
            retained.add(key);
            let component = this.components.get(key);
            if (!component) {
                component = parent.append({type:`jm-${ref.kind}`, attributes:{'data-jm-kind':ref.kind,'data-jm-id':ref.id}})[0]!;
                this.components.set(key, component);
            }
            if (component.parent() !== parent || parent.components().indexOf(component) !== index) component.move(parent, {at:index});
            component.set('jmData', copy(data));
            component.set('content', escapeText(data.title ?? data.label ?? data.headline ?? ''));
            component.set('name', String(data.title || data.label || data.headline || this.options.names[String(data.type)] || ref.id));
            const style: Record<string,string> = {};
            for (const [property, css] of [['background_color','background-color'],['text_color','color'],['align','text-align']] as const) {
                if (typeof data[property] === 'string') style[css] = data[property];
            }
            if (typeof data.padding === 'number') style.padding = `${data.padding}px`;
            component.setStyle(style);
            component.set('draggable', this.options.editable && ref.kind !== 'thank_you');
            return component;
        };
        schema.blocks.forEach((block,index) => reconcile(this.pages.get('landing')!, {kind:'block',id:block.id}, block, index));
        schema.steps.forEach((step,index) => {
            const parent = reconcile(this.pages.get('form')!, {kind:'step',id:step.id}, step, index);
            schema.fields.filter(field => field.step === step.id).forEach((field,fieldIndex) => reconcile(parent, {kind:'field',id:field.key}, field, fieldIndex));
        });
        reconcile(this.pages.get('thank_you')!, {kind:'thank_you',id:'thank_you'}, schema.thank_you, 0);
        // Move retained fields before removing a deleted step that previously owned them.
        for (const [key,component] of this.components) {
            if (!retained.has(key)) {component.remove();this.components.delete(key);}
        }
        if (this.selected && !retained.has(keyOf(this.selected))) {this.selected = null;this.editor.select();}
        else if (this.selected) this.editor.select(this.components.get(keyOf(this.selected)));
        this.signature = signature;
    }

    read(kind: Kind, id: string): Entity | null {
        const value = this.components.get(keyOf({kind,id}))?.get('jmData') as Entity | undefined;
        return value ? copy(value) : null;
    }

    select(kind: Kind, id: string, notify = true): boolean {
        if (this.disposed) return false;
        return Boolean(this.editor.runCommand('jm:select', {ref:{kind,id},notify}));
    }

    clearSelection(): void {
        this.selected = null;
        this.editor.select();
    }

    commit(kind: Kind, id: string, property: string, value: string): boolean {
        return !this.disposed && Boolean(this.editor.runCommand('jm:commit', {ref:{kind,id},property,value}));
    }

    private commitComponent(ref: Ref, property: string, value: string): boolean {
        if (!this.options.editable) return false;
        const allowed = ref.kind === 'field' ? ['label'] : ref.kind === 'step' ? ['title'] : ref.kind === 'thank_you' ? ['headline','text','button_text'] : ['title','text','button_text'];
        const component = this.components.get(keyOf(ref));
        if (!component || !allowed.includes(property) || typeof value !== 'string') return false;
        if ((ref.kind === 'field' || ref.kind === 'thank_you') && !value.trim()) return false;
        const limit = ref.kind === 'field' ? 150 : property === 'button_text' ? 100 : ['title','headline'].includes(property) ? 255 : ref.kind === 'thank_you' ? 5000 : 10000;
        const next = value.slice(0,limit);
        component.set('jmData', {...component.get('jmData'), [property]:next});
        component.set('content', escapeText(next));
        try {this.options.onCommit(ref.kind,ref.id,property,next);} finally {
            this.signature = '';
            this.sync(this.options.getSchema());
        }
        return true;
    }

    move(kind: Kind, id: string, target: Target): boolean {
        return !this.disposed && Boolean(this.editor.runCommand('jm:move', {ref:{kind,id},target}));
    }

    private moveComponent(ref: Ref, target: Target): boolean {
        if (!this.options.editable || (ref.kind === target.kind && ref.id === target.id)) return false;
        const source = this.components.get(keyOf(ref)), destination = this.components.get(keyOf(target));
        if (!source || !destination || ref.kind === 'thank_you') return false;
        if (ref.kind === 'field' ? !['field','step'].includes(target.kind) : ref.kind !== target.kind) return false;
        const parent = ref.kind === 'field' && target.kind === 'step' ? destination : destination.parent();
        if (!parent) return false;
        const schema = this.options.getSchema();
        if (ref.kind === 'field' && protectedFields.has(ref.id)) {
            const stepId = target.kind === 'step' ? target.id : String((destination.get('jmData') as Entity).step);
            const rules = schema.rules;
            if (Array.isArray(rules) && rules.some(rule => rule && typeof rule === 'object' && !Array.isArray(rule) && rule.action === 'SKIP_STEP' && rule.target === stepId)) {this.options.onError('Identitas wajib tidak boleh dipindahkan ke langkah yang dilewati kondisi.');return false;}
        }
        const children = parent.components().models.filter(component => component !== source);
        const index = target.kind === 'step' && ref.kind === 'field' ? children.length : children.indexOf(destination) + Number(target.after);
        source.move(parent, {at:Math.max(0,index)});
        try {this.options.onMove(ref.kind,ref.id,target);} finally {
            this.signature = '';
            this.sync(this.options.getSchema());
        }
        return true;
    }

    registerAsset(kind: AddKind, id: string, label: string): void {
        if (this.editor.Blocks.get(`${kind}:${id}`)) return;
        this.editor.Blocks.add(`${kind}:${id}`, {label,content:{type:kind === 'add-block' ? 'jm-block' : 'jm-field',attributes:{'data-jm-asset':id}}});
    }

    add(kind: AddKind, id: string, target: Target): boolean {
        return !this.disposed && Boolean(this.editor.runCommand('jm:add', {kind,id,target}));
    }

    layers(page: Page): Layer[] {
        const rows: Layer[] = [];
        const visit = (component: Component): void => {
            const attributes = component.getAttributes(), kind = attributes['data-jm-kind'] as Kind | undefined;
            if (kind) {
                const data = component.get('jmData') as Entity;
                rows.push({kind,id:String(attributes['data-jm-id']),label:String(data.label || data.title || data.headline || this.options.names[String(data.type)] || ''),hidden:Boolean(data.hidden || data.type === 'hidden'),componentType:String(component.get('type'))});
            }
            component.components().forEach(visit);
        };
        this.pages.get(page)?.components().forEach(visit);
        return rows;
    }

    inspect() {
        return {name:'grapesjs',version:grapesjs.version,editable:this.options.editable,selected:this.selected ? {...this.selected} : null,paletteCount:this.editor.Blocks.getAll().length,pages:{landing:this.layers('landing'),form:this.layers('form'),thank_you:this.layers('thank_you')}};
    }

    destroy(): void {
        if (this.disposed) return;
        this.disposed = true;
        this.editor.destroy();
        this.components.clear();
        this.pages.clear();
    }
}

export function createFunnelEngine(options: Options): FunnelEditorEngine {
    return new FunnelEditorEngine(options);
}
