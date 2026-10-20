import test from 'node:test';
import assert from 'node:assert/strict';
import {BuilderModel} from '../../public/js/funnel-builder-model.js';
const schema=()=>({blocks:[{id:'hero',type:'hero'}],steps:[{id:'basic',title:'Data dasar'},{id:'extra',title:'Tambahan'}],fields:[{key:'name',label:'Nama',type:'text',step:'basic',required:true},{key:'whatsapp',label:'WhatsApp',type:'phone',step:'basic',required:true},{key:'age',label:'Usia',type:'number',step:'extra',required:false}],rules:[{field:'age',operator:'lt',value:18,action:'SHOW_NOTICE',message:'Periksa usia'}],thank_you:{headline:'Berhasil',text:'Terima kasih'}});
test('deleting a form block preserves field definitions and supports undo and redo',()=>{
 const data=schema();data.blocks.push({id:'form',type:'form'});
 const model=new BuilderModel(data);model.removeBlock('form');
 assert.deepEqual(model.schema.blocks.map(block=>block.id),['hero']);
 assert.deepEqual(model.schema.fields,data.fields);
 model.undo();assert.equal(model.schema.blocks[1].id,'form');
 model.redo();assert.equal(model.schema.blocks.length,1);
 assert.throws(()=>model.removeBlock('hero'),/Minimal satu blok/);
 assert.equal(model.schema.blocks[0].id,'hero');
});
test('rename updates condition references; undo and redo restore them',()=>{const model=new BuilderModel(schema());model.updateField('age',{key:'years'});assert.equal(model.schema.rules[0].field,'years');model.undo();assert.equal(model.schema.rules[0].field,'age');model.redo();assert.equal(model.schema.rules[0].field,'years');});
test('moving fields preserves data and order across steps',()=>{const model=new BuilderModel(schema());model.moveField('age','basic','whatsapp');assert.deepEqual(model.schema.fields.map(field=>field.key),['name','age','whatsapp']);assert.equal(model.schema.fields[1].step,'basic');model.undo();assert.equal(model.schema.fields[2].step,'extra');});
test('duplicate has unique key and deletion removes dependent rules',()=>{const model=new BuilderModel(schema());const key=model.duplicateField('age');assert.notEqual(key,'age');assert.equal(model.schema.fields.find(field=>field.key===key).required,false);model.removeField('age');assert.equal(model.schema.rules.length,0);model.undo();assert.equal(model.schema.rules.length,1);});
test('identity fields cannot be removed or changed to optional',()=>{const model=new BuilderModel(schema());assert.throws(()=>model.removeField('name'));assert.throws(()=>model.updateField('whatsapp',{required:false}));assert.equal(model.schema.fields.length,3);assert.equal(model.undoStack.length,0);});
test('removing a step moves its fields without losing answers definition',()=>{const model=new BuilderModel(schema());model.removeStep('extra','basic');assert.equal(model.schema.steps.length,1);assert.equal(model.schema.fields.find(field=>field.key==='age').step,'basic');assert.throws(()=>model.removeStep('basic','basic'));});
test('step renaming updates fields and skip rules',()=>{const data=schema();data.rules.push({field:'age',action:'SKIP_STEP',target:'extra'});const model=new BuilderModel(data);model.updateStep('extra',{id:'details'});assert.equal(model.schema.fields[2].step,'details');assert.equal(model.schema.rules[1].target,'details');assert.throws(()=>model.moveField('name','details'));});
test('invalid rename rolls back transaction without changing history',()=>{const model=new BuilderModel(schema());assert.throws(()=>model.updateField('age',{key:'name'}));assert.equal(model.schema.fields[2].key,'age');assert.equal(model.undoStack.length,0);});
test('redo is cleared after a new edit and imported schema is isolated',()=>{const model=new BuilderModel(schema());model.addStep();model.undo();model.addField('select','basic','Pilihan');assert.equal(model.redoStack.length,0);const imported=schema();model.replace(imported);imported.fields[0].label='Mutasi luar';assert.equal(model.schema.fields[0].label,'Nama');});

test('malformed imported element is rejected without damaging draft',()=>{const model=new BuilderModel(schema());const invalid=schema();invalid.fields.push(null);assert.throws(()=>model.replace(invalid));assert.equal(model.schema.fields.length,3);assert.equal(model.undoStack.length,0);});

test('duplicating a step copies optional fields and local rules with new references',()=>{
 const data=schema();data.rules.push({field:'age',action:'REQUIRE_FIELD',target:'age'});
 const model=new BuilderModel(data),id=model.duplicateStep('extra');
 const field=model.schema.fields.find(field=>field.step===id);
 assert.notEqual(field.key,'age');assert.equal(model.schema.steps[2].id,id);
 assert.equal(model.schema.rules[3].field,field.key);assert.equal(model.schema.rules[3].target,field.key);
 assert.equal(model.schema.fields.filter(field=>field.key==='name').length,1);
 model.undo();assert.equal(model.schema.steps.length,2);assert.equal(model.schema.fields.length,3);
 const basic=model.duplicateStep('basic');assert.equal(model.schema.fields.filter(field=>field.step===basic).length,0);
});

test('deleting a tracked section removes only its rules and undo restores the binding',()=>{const data=schema();data.blocks.push({id:'form',type:'form'});data.tracking={enabled:true,destination_ids:[1],rules:[{id:'hero-rule',section_key:'hero'},{id:'form-rule',section_key:'form'}]};const model=new BuilderModel(data);model.removeBlock('hero');assert.deepEqual(model.schema.tracking.rules.map(rule=>rule.id),['form-rule']);model.undo();assert.deepEqual(model.schema.tracking,data.tracking);});
