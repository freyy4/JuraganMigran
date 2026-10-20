export const PROTECTED_FIELDS = ['name', 'whatsapp'];
const copy = value => JSON.parse(JSON.stringify(value));
const FIELD_ACTIONS = ['SHOW_FIELD', 'HIDE_FIELD', 'REQUIRE_FIELD', 'SET_VALUE'];

export function assertStructure(schema) {
    if (!schema || !Array.isArray(schema.fields) || !Array.isArray(schema.steps) || !Array.isArray(schema.blocks) || !Array.isArray(schema.rules) || !schema.thank_you || typeof schema.thank_you.headline!=='string' || typeof schema.thank_you.text!=='string') throw new Error('Struktur schema tidak lengkap.');
    if (schema.fields.some(field=>!field||typeof field.key!=='string'||typeof field.label!=='string'||typeof field.step!=='string'||typeof field.type!=='string') || schema.steps.some(step=>!step||typeof step.id!=='string'||typeof step.title!=='string') || schema.blocks.some(block=>!block||typeof block.id!=='string'||typeof block.type!=='string') || schema.rules.some(rule=>!rule||typeof rule.field!=='string'||typeof rule.action!=='string')) throw new Error('Struktur elemen schema tidak valid.');
}

export class BuilderModel {
    constructor(schema) {
        assertStructure(schema);
        this.schema = copy(schema);
        this.undoStack = [];
        this.redoStack = [];
        this.lastGroup = null;
        this.lastChange = 0;
    }
    mutate(callback, group = null) {
        const before = copy(this.schema);
        try { callback(this.schema); } catch (error) { this.schema = before; throw error; }
        if (JSON.stringify(before) === JSON.stringify(this.schema)) return false;
        if (!group || group !== this.lastGroup || Date.now() - this.lastChange > 750) {
            this.undoStack.push(before);
            if (this.undoStack.length > 60) this.undoStack.shift();
        }
        this.redoStack = [];
        this.lastGroup = group;
        this.lastChange = Date.now();
        return true;
    }
    unique(prefix, keys) {
        let i = 1;
        while (keys.includes(`${prefix}_${i}`)) i++;
        return `${prefix}_${i}`;
    }
    addField(type, step, label) {
        const key = this.unique('field', this.schema.fields.map(field => field.key));
        this.mutate(schema => {
            if (schema.fields.length >= 80) throw new Error('Maksimal 80 field.');
            if (!schema.steps.some(item => item.id === step)) throw new Error('Langkah tidak tersedia.');
            const field = {key, label, type, step, required: false, sensitivity: type === 'phone' || type === 'email' ? 'CONTACT' : 'GENERAL'};
            if (['select', 'searchable_select', 'radio', 'multi_select', 'province_city'].includes(type)) field.options = ['Pilihan 1', 'Pilihan 2'];
            schema.fields.push(field);
        });
        return key;
    }
    updateField(key, patch, group = null) {
        this.mutate(schema => {
            const field = schema.fields.find(item => item.key === key);
            if (!field) throw new Error('Field tidak tersedia.');
            if (PROTECTED_FIELDS.includes(key) && (patch.key || patch.type || patch.required === false)) throw new Error('Nama dan WhatsApp merupakan identitas wajib.');
            if (patch.key && patch.key !== key) {
                if (!/^[a-z][a-z0-9_]{0,39}$/.test(patch.key) || schema.fields.some(item => item.key === patch.key)) throw new Error('Key harus unik, diawali huruf kecil, dan maksimal 40 karakter.');
                schema.rules.forEach(rule => {
                    if (rule.field === key) rule.field = patch.key;
                    if (FIELD_ACTIONS.includes(rule.action) && rule.target === key) rule.target = patch.key;
                });
            }
            Object.assign(field, patch);
        }, group);
    }
    duplicateField(key) {
        const newKey = this.unique('field', this.schema.fields.map(field => field.key));
        this.mutate(schema => {
            if (schema.fields.length >= 80) throw new Error('Maksimal 80 field.');
            const index = schema.fields.findIndex(field => field.key === key);
            if (index < 0) throw new Error('Field tidak tersedia.');
            const field = copy(schema.fields[index]);
            Object.assign(field, {key: newKey, label: `${field.label} (salinan)`, required: false});
            schema.fields.splice(index + 1, 0, field);
        });
        return newKey;
    }
    removeField(key) {
        this.mutate(schema => {
            if (PROTECTED_FIELDS.includes(key)) throw new Error('Nama dan WhatsApp tidak dapat dihapus.');
            schema.fields = schema.fields.filter(field => field.key !== key);
            schema.rules = schema.rules.filter(rule => rule.field !== key && !(FIELD_ACTIONS.includes(rule.action) && rule.target === key));
        });
    }
    removeBlock(id) {
        this.mutate(schema => {
            if (!schema.blocks.some(block => block.id === id)) return;
            if (schema.blocks.length <= 1) throw new Error('Minimal satu blok harus tersedia.');
            schema.blocks = schema.blocks.filter(block => block.id !== id);
            if(schema.tracking?.rules&&!['form','thank_you','whatsapp'].includes(id))schema.tracking.rules=schema.tracking.rules.filter(rule=>rule.section_key!==id);
        });
    }
    moveField(key, step, beforeKey = null) {
        this.mutate(schema => {
            if (!schema.steps.some(item => item.id === step)) throw new Error('Langkah tidak tersedia.');
            const field = schema.fields.find(item => item.key === key);
            if (!field || beforeKey === key) return;
            if (PROTECTED_FIELDS.includes(key) && schema.rules.some(rule => rule.action === 'SKIP_STEP' && rule.target === step)) throw new Error('Identitas wajib tidak boleh dipindahkan ke langkah yang dilewati kondisi.');
            schema.fields = schema.fields.filter(item => item.key !== key);
            field.step = step;
            const index = beforeKey ? schema.fields.findIndex(item => item.key === beforeKey && item.step === step) : -1;
            if (index < 0) schema.fields.push(field); else schema.fields.splice(index, 0, field);
        });
    }
    addStep() {
        const id = this.unique('step', this.schema.steps.map(step => step.id));
        this.mutate(schema => {
            if (schema.steps.length >= 10) throw new Error('Maksimal 10 langkah.');
            schema.steps.push({id, title: `Langkah ${schema.steps.length + 1}`});
        });
        return id;
    }
    updateStep(id, patch, group = null) {
        this.mutate(schema => {
            const step = schema.steps.find(item => item.id === id);
            if (!step) throw new Error('Langkah tidak tersedia.');
            if (patch.id && patch.id !== id) {
                if (!/^[a-z][a-z0-9_]{0,39}$/.test(patch.id) || schema.steps.some(item => item.id === patch.id)) throw new Error('ID langkah harus unik dan diawali huruf kecil.');
                schema.fields.forEach(field => { if (field.step === id) field.step = patch.id; });
                schema.rules.forEach(rule => { if (rule.action === 'SKIP_STEP' && rule.target === id) rule.target = patch.id; });
            }
            Object.assign(step, patch);
        }, group);
    }
    duplicateStep(id) {
        const newId=this.unique('step',this.schema.steps.map(step=>step.id));
        this.mutate(schema=>{
            const index=schema.steps.findIndex(step=>step.id===id);
            if(index<0)throw new Error('Langkah tidak tersedia.');
            if(schema.steps.length>=10)throw new Error('Maksimal 10 langkah.');
            const fields=schema.fields.filter(field=>field.step===id&&!PROTECTED_FIELDS.includes(field.key));
            if(schema.fields.length+fields.length>80)throw new Error('Maksimal 80 field.');
            schema.steps.splice(index+1,0,{...copy(schema.steps[index]),id:newId,title:schema.steps[index].title+' (salinan)'});
            const mapping={};fields.forEach(field=>{const key=this.unique('field',schema.fields.map(item=>item.key));mapping[field.key]=key;schema.fields.push({...copy(field),key,step:newId});});
            const rules=schema.rules.filter(rule=>mapping[rule.field]&&(FIELD_ACTIONS.includes(rule.action)?!!mapping[rule.target]:['SHOW_NOTICE','BLOCK_SUBMISSION'].includes(rule.action)));
            if(schema.rules.length+rules.length>50)throw new Error('Maksimal 50 kondisi.');
            rules.forEach(rule=>schema.rules.push({...copy(rule),field:mapping[rule.field],...(mapping[rule.target]?{target:mapping[rule.target]}:{})}));
        });
        return newId;
    }
    removeStep(id, destination) {
        this.mutate(schema => {
            if (schema.steps.length < 2 || id === destination || !schema.steps.some(step => step.id === destination)) throw new Error('Pilih langkah tujuan untuk memindahkan field.');
            schema.fields.filter(field => field.step === id).forEach(field => {
                if (PROTECTED_FIELDS.includes(field.key) && schema.rules.some(rule => rule.action === 'SKIP_STEP' && rule.target === destination)) throw new Error('Langkah tujuan identitas wajib tidak dapat dilewati kondisi.');
                field.step = destination;
            });
            schema.steps = schema.steps.filter(step => step.id !== id);
            schema.rules = schema.rules.filter(rule => !(rule.action === 'SKIP_STEP' && rule.target === id));
        });
    }
    move(list, id, beforeId, property = 'id') {
        this.mutate(schema => {
            const item = schema[list].find(item => item[property] === id);
            if (!item || id === beforeId) return;
            schema[list] = schema[list].filter(item => item[property] !== id);
            const index = schema[list].findIndex(item => item[property] === beforeId);
            if (index < 0) schema[list].push(item); else schema[list].splice(index, 0, item);
        });
    }
    replace(schema) {
        assertStructure(schema);
        this.mutate(() => { this.schema = copy(schema); });
    }
    undo() {
        if (!this.undoStack.length) return;
        this.redoStack.push(copy(this.schema)); this.schema = this.undoStack.pop(); this.lastGroup = null;
    }
    redo() {
        if (!this.redoStack.length) return;
        this.undoStack.push(copy(this.schema)); this.schema = this.redoStack.pop(); this.lastGroup = null;
    }
}
