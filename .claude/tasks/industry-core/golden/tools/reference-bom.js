// Reference BOM engine (scratch). Usage: node bom.js <sdeDir> <configJson>
// Formula per job and material: max(runs, ceil(round(runs*base*meMod*structMod*rigMod, 2)))  (EVE Ref ManufactureCalculator, MIT-0)
// Demand aggregated over the whole plan before computing runs = ceil(demand / outputPerRun) (Ravworks planner behaviour).
const fs=require('fs');
const [sdeDir,cfgPath]=process.argv.slice(2);
const cfg=JSON.parse(fs.readFileSync(cfgPath,'utf8'));
const tsv=f=>fs.readFileSync(sdeDir+'/'+f,'utf8').trim().split('\n').map(l=>l.split('\t'));
const types={};for(const [id,name,gid,gname,cid] of tsv('types.tsv'))types[+id]={name,gid:+gid,gname,cid:+cid};
const byName={};for(const id in types)byName[types[id].name]=+id;
const mats={};for(const [bp,a,m,q] of tsv('mats.tsv')){(mats[bp+'_'+a]??=[]).push([+m,+q]);}
const producer={};for(const [bp,a,p,q] of tsv('prods.tsv')){if(a==='1'||a==='11'){const k=+p; if(!producer[k]||a==='1')producer[k]={bp:+bp,act:+a,out:+q};}}
const buy=new Set((cfg.buy||[]).map(n=>byName[n]));
const isBuilt=t=>producer[t]&&!buy.has(t);
// list of multiplicative modifiers (structure, rig) for the job producing t
function mods(t){const p=producer[t];const n=types[t].name;
  if((cfg.modsByName||{})[n])return cfg.modsByName[n];
  return p.act===11?(cfg.reactionMods||[]):(cfg.manufacturingMods||[]);}
function me(t,isRoot){const p=producer[t]; if(p.act===11)return 0; return isRoot?cfg.rootMe:(cfg.intermediateMe??10);}
const depth={};
function visit(t,d){if(depth[t]!==undefined&&depth[t]>=d)return;depth[t]=d;if(!isBuilt(t))return;const p=producer[t];for(const [m] of mats[p.bp+'_'+p.act]||[])visit(m,d+1);}
for(const r of cfg.roots)visit(byName[r.name],0);
const demand={};const rootRuns={};
for(const r of cfg.roots){rootRuns[byName[r.name]]=r.runs;}
const order=Object.keys(depth).map(Number).sort((a,b)=>depth[a]-depth[b]);
const jobs=[];
for(const t of order){
  if(!isBuilt(t))continue;
  const p=producer[t];
  const runs=(depth[t]===0&&rootRuns[t]!==undefined)?rootRuns[t]:Math.ceil((demand[t]||0)/p.out);
  if(runs<=0)continue;
  const e=me(t,depth[t]===0);const ms=mods(t);
  const inputs={};
  for(const [m,q] of mats[p.bp+'_'+p.act]||[]){
    let x=runs*q*(1-e/100); for(const k of ms)x*=k;
    const need=Math.max(runs,Math.ceil(Math.round(x*100)/100));
    inputs[types[m].name]=need;
    demand[m]=(demand[m]||0)+need;
  }
  jobs.push({product:types[t].name,typeId:t,activity:p.act===11?'reaction':'manufacturing',runs,outputPerRun:p.out,quantityProduced:runs*p.out,
    quantityNeeded:depth[t]===0?runs*p.out:demand[t],surplus:runs*p.out-(depth[t]===0?runs*p.out:demand[t]),me:e,modifiers:ms,inputs:Object.fromEntries(Object.entries(inputs).sort())});
}
const leaves={};for(const t in demand){if(!isBuilt(+t))leaves[types[t].name]={typeId:+t,quantity:demand[t]};}
console.log(JSON.stringify({jobs:jobs.sort((a,b)=>a.product.localeCompare(b.product)),leaves:Object.fromEntries(Object.entries(leaves).sort())},null,1));
