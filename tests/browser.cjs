/** Real Chromium tests against PHP-rendered markup; no WordPress services or paid APIs. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '..');
fs.mkdirSync(path.join(root, 'test-results'), { recursive: true });
let requests = [], pending = 0, peak = 0, failZero = false;
const server = http.createServer((req, res) => {
  const match = req.url.match(/^\/frames\/(\d+)\.svg/);
  if (match) {
    const index = Number(match[1]); requests.push(index);
    if (index !== 99) { pending++; peak = Math.max(peak, pending); }
    return setTimeout(() => {
      if (index !== 99) pending--;
      if (failZero && index === 0) {res.writeHead(404); res.end();return;}
      res.writeHead(200, {'Content-Type':'image/svg+xml','Cache-Control':'no-store'});
      res.end(`<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="900"><rect width="1600" height="900" fill="hsl(${index*3.5},55%,68%)"/><rect x="30" y="30" width="1540" height="840" rx="30" fill="none" stroke="#17213c" stroke-width="20"/><text x="800" y="500" font-family="sans-serif" font-size="180" text-anchor="middle">${index}</text></svg>`);
    }, index === 99 ? 0 : 40);
  }
  const file = req.url === '/' ? 'tests/fixture.html' : req.url.slice(1);
  if (!/^(tests\/fixture\.html|assets\/frontend\/[\w.-]+)$/.test(file)) {res.writeHead(404);res.end();return;}
  res.setHeader('Content-Type', file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'text/html');
  res.end(fs.readFileSync(path.join(root,file)));
});
(async()=>{
  await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
  const browser = await chromium.launch(process.env.CHROMIUM_PATH ? {executablePath:process.env.CHROMIUM_PATH} : {});
  const origin='http://127.0.0.1:'+server.address().port;
  const results=[];
  async function newPage(viewport, options={}){
    const context=await browser.newContext({viewport,...options});
    const page=await context.newPage();
    page.on('pageerror',e=>{throw e;});
    await page.addInitScript(()=>{
      window.__painted=-1;window.__cachePeak=0;
      const draw=CanvasRenderingContext2D.prototype.drawImage;
      CanvasRenderingContext2D.prototype.drawImage=function(image,...args){window.__painted=Number(image.src.match(/(\d+)\.svg/)[1]);return draw.call(this,image,...args);};
      const NativeMap=Map;
      window.Map=class extends NativeMap{set(k,v){const out=super.set(k,v);if(v instanceof HTMLImageElement)window.__cachePeak=Math.max(window.__cachePeak,this.size);return out;}};
    });
    await page.goto(origin);
    return page;
  }
  async function seek(page,pct){
    const target=await page.evaluate(p=>{
      const wrapper=document.querySelector('[data-shseq]'),stage=wrapper.querySelector('.shseq-stage');
      const top=parseFloat(getComputedStyle(stage).top)||0;
      const r=wrapper.getBoundingClientRect();
      scrollTo(0,scrollY+r.top-top+p*(r.height-stage.getBoundingClientRect().height));
      return Math.round(p*99);
    },pct);
    await page.waitForFunction(i=>window.__painted===i,target);
  }
  try{
    for(const [name,width,height,layout] of [['desktop',1536,960,'desktop'],['phone-portrait',390,844,'portrait'],['phone-landscape',844,390,'landscape'],['tablet-portrait',820,1180,'portrait'],['tablet-landscape',1180,820,'landscape']]){
      const page=await newPage({width,height});
      await seek(page,0.5);
      assert.equal(await page.locator('[data-shseq]').getAttribute('data-layout'),layout);
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'no horizontal overflow');
      const metrics=await page.evaluate(()=>{
        const v=document.querySelector('.shseq-visual').getBoundingClientRect();
        const a=document.querySelector('.shseq-overlay:not([hidden]) a').getBoundingClientRect();
        return {w:v.width,h:v.height,cta:a.height,bottom:a.bottom,top:a.top};
      });
      assert(metrics.w>100 && metrics.h>=100,'visible image');
      assert(metrics.cta>=44 && metrics.top>=0 && metrics.bottom<=height+1,'visible touch target');
      assert.equal(await page.locator('.shseq-overlay:not([hidden])').count(),1);
      assert.equal(await page.locator('.shseq-stage[aria-hidden="true"]').count(),0);
      await page.screenshot({path:`test-results/${name}.png`});
      results.push(name);await page.context().close();
    }
    const page=await newPage({width:390,height:844});
    await seek(page,0.4);await page.setViewportSize({width:844,height:390});await seek(page,0.7);
    assert.equal(await page.locator('[data-shseq]').getAttribute('data-layout'),'landscape');
    await page.setViewportSize({width:390,height:844});await seek(page,0.1);results.push('live orientation changes');
    peak=0;
    for(const pct of [0.9,0.2,0.8,0,1,0.45,0.6,0.3]) await seek(page,pct);
    // Instrumentation observes insertion before eviction: at most 9 then immediately back to 8.
    assert((await page.evaluate(()=>window.__cachePeak))<=9,'bounded decoded cache');
    assert(peak<=2,'at most two in-flight frame requests');results.push('fast jumps, load completion redraw, bounded memory and concurrency');
    await seek(page,0);await page.locator('.shseq-overlay:not([hidden]) a').focus();
    await seek(page,1);
    assert.equal(await page.evaluate(()=>document.activeElement.closest('.shseq-overlay').hidden),false,'focused CTA not hidden');
    await page.keyboard.press('Tab');results.push('keyboard focus survives scroll');
    await page.emulateMedia({reducedMotion:'reduce'});
    assert.equal(await page.locator('[data-shseq]').evaluate(e=>e.classList.contains('is-enhanced')),false);
    assert.equal(await page.locator('.shseq-overlay:not([hidden])').count(),2);
    await page.emulateMedia({reducedMotion:'no-preference'});await seek(page,0.5);results.push('dynamic reduced motion');
    const snapshot=await page.locator('[data-shseq]').evaluate(el=>el.outerHTML);
    await page.locator('[data-shseq]').evaluate(el=>el.remove());
    await page.locator('main').evaluate((el,html)=>el.innerHTML=html,snapshot);await seek(page,0.2);results.push('Elementor-style widget replacement');
    await page.context().close();
    requests=[];const lazy=await newPage({width:390,height:600});await lazy.waitForTimeout(150);
    assert.deepEqual(requests,[99],'only server poster loaded before intersection');
    await seek(lazy,0);assert(requests.length<=5,'does not preload entire sequence');await lazy.context().close();results.push('lazy loading');
    failZero=true;const broken=await newPage({width:1536,height:960});await seek(broken,0.5);await broken.context().close();failZero=false;results.push('broken first frame does not block later frames');
    for(const opts of [{javaScriptEnabled:false},{reducedMotion:'reduce'}]){
      const staticPage=await newPage({width:390,height:844},opts);
      assert.equal(await staticPage.locator('.shseq-overlay').count(),2);
      for(const link of await staticPage.locator('.shseq-overlay a').all()) assert(await link.isVisible(),'static CTA visible');
      assert.equal(await staticPage.locator('.shseq-canvas').isVisible(),false);
      assert((await staticPage.locator('.shseq-static-fallback').boundingBox()).height>100);
      await staticPage.screenshot({path:`test-results/${opts.javaScriptEnabled===false?'no-js':'reduced-motion'}.png`,fullPage:true});
      await staticPage.context().close();
    }
    results.push('no-JS and reduced-motion static content');
    const sd=await browser.newContext({viewport:{width:390,height:844}});
    await sd.addInitScript(()=>Object.defineProperty(navigator,'connection',{value:{saveData:true,addEventListener(){},removeEventListener(){}}}));
    const saved=await sd.newPage();await saved.goto(origin);assert.equal(await saved.locator('[data-shseq]').evaluate(e=>e.classList.contains('is-enhanced')),false);await sd.close();results.push('Save-Data fallback');
    fs.writeFileSync('test-results/results.json',JSON.stringify(results,null,2));
    console.log('PASS '+results.length+' browser scenarios\n'+results.join('\n'));
  }finally{await browser.close();server.close();}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
