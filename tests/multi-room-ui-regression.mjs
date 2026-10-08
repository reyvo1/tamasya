// Link the actual ES-module graph without starting a browser or evaluating app code.
// Syntax-only checks cannot catch missing named exports in lazy chunks.
import {SourceTextModule,createContext} from 'node:vm';
import fs from 'node:fs';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
const context=createContext({}),modules=new Map();
function load(url){
 const name=url.href;if(modules.has(name))return modules.get(name);
 assert.equal(url.protocol,'file:');const module=new SourceTextModule(fs.readFileSync(fileURLToPath(url),'utf8'),{context,identifier:name});modules.set(name,module);return module;
}
for(const path of ['../assets/chunks/multi-room.js','../assets/chunks/rooms.js']){
 const module=load(new URL(path,import.meta.url));if(module.status==='unlinked')await module.link((specifier,ref)=>load(new URL(specifier,ref.identifier)));
 assert.equal(module.status,'linked');console.log('PASS actual static/lazy ES imports and named exports: '+path);
}
console.log('PASS all '+modules.size+' dependency modules linked without app evaluation.');
