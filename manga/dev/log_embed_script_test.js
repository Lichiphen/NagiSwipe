/* Validate provider resize messages and mobile fitting without contacting providers. */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../server/viewer/log-embed.js'), 'utf8');
const events = new Map();
const bluesky = {contentWindow:{},style:{}};
const threads = {contentWindow:{},style:{}};
let width = 343, providerScans = 0;
const wrap = {getBoundingClientRect:() => ({width})};
const facebook = {src:'https://www.facebook.com/plugins/post.php?href=https%3A%2F%2Fwww.facebook.com%2FEngineering%2Fposts%2F123456789&width=500',style:{},dataset:{},contentWindow:{},get offsetHeight() { return parseFloat(this.style.height) || 650; },parentElement:wrap,closest:() => wrap};
const document = {
    documentElement:{dataset:{embedScripts:'off'}},
    querySelector:() => { providerScans++; return null; },
    querySelectorAll:selector => selector === '.embeddedfacebook iframe' ? [facebook] : selector === '.embeddedbluesky iframe' ? [bluesky] : selector === '.embeddedthreads iframe' ? [threads] : [],
};
const window = {addEventListener:(type,handler) => events.set(type,handler)};
vm.runInNewContext(source,{window,document,URL,URLSearchParams,location:{hostname:'log.example',origin:'https://log.example'},ResizeObserver:class {observe() {}},console});
assert.equal(new URL(facebook.src).searchParams.get('width'),'350');
assert.equal(facebook.style.width,'350px');
const channel = new URL(new URL(facebook.src).searchParams.get('channel'));
assert.equal(channel.origin,'https://staticxx.facebook.com');
assert.match(channel.hash,/cb=nagilog1&domain=log\.example&.*origin=https%3A%2F%2Flog\.example%2Fnagilog1/);
assert.equal(facebook.style.transform,'scale(0.98)');
assert.ok(Math.abs(parseFloat(facebook.style.marginBottom)+13)<0.01);
assert.equal(providerScans,0,'Admin preview must not scan/load external provider scripts');
width = 674; window.NagiLogEmbeds.load();
assert.equal(new URL(facebook.src).searchParams.get('width'),'500');
assert.equal(facebook.style.transform,'');
const message = events.get('message');
message({origin:'https://embed.bsky.app',source:bluesky.contentWindow,data:{height:821.2}});
assert.equal(bluesky.style.height,'822px');
for (const event of [
    {origin:'https://evil.example',source:bluesky.contentWindow,data:{height:600}},
    {origin:'https://embed.bsky.app',source:{},data:{height:600}},
    {origin:'https://embed.bsky.app',source:bluesky.contentWindow,data:{height:Infinity}},
    {origin:'https://embed.bsky.app',source:bluesky.contentWindow,data:{height:3001}},
    {origin:'https://embed.bsky.app',source:bluesky.contentWindow,data:{height:0}},
    {origin:'https://embed.bsky.app',source:bluesky.contentWindow,data:{height:'600; background:red'}},
]) message(event);
assert.equal(bluesky.style.height,'822px','Wrong origins/sources and invalid sizes must have no effect');
message({origin:'https://www.threads.com',source:threads.contentWindow,data:'610'});
assert.equal(threads.style.height,'610px');
message({origin:'https://www.threads.com',source:threads.contentWindow,data:'610px'});
assert.equal(threads.style.height,'610px');
message({origin:'https://www.facebook.com',source:facebook.contentWindow,data:'type=resize&width=500&height=603&cb=nagilog1'});
assert.equal(facebook.style.height,'603px','Facebook reports its height through the channel');
message({origin:'https://www.facebook.com',source:{},data:'type=resize&width=500&height=900'});
message({origin:'https://evil.example',source:facebook.contentWindow,data:'type=resize&width=500&height=900'});
assert.equal(facebook.style.height,'603px');
const previous = facebook.src;
facebook.src='https://evil.example/plugins/post.php?width=500';
window.NagiLogEmbeds.load();
assert.equal(facebook.src,'https://evil.example/plugins/post.php?width=500','Never mutate a provider at an unknown origin');
facebook.src=previous;
console.log('PASS: mobile Facebook fitting, admin script isolation, and validated Bluesky/Threads/Facebook resize messages');
