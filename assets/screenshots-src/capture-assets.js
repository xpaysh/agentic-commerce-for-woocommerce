const { chromium } = require('playwright');
const path = require('path');

const ASSETS = [
  { src: 'banner.html', out: 'banner-772x250.png',  w: 772,  h: 250,  scale: 1 },
  { src: 'banner.html', out: 'banner-1544x500.png', w: 1544, h: 500,  scale: 1 },
  { src: 'icon.html',   out: 'icon-128x128.png',    w: 128,  h: 128,  scale: 1, source: { w: 256, h: 256 } },
  { src: 'icon.html',   out: 'icon-256x256.png',    w: 256,  h: 256,  scale: 1 },
];

(async () => {
  const browser = await chromium.launch();
  const outDir = path.resolve(__dirname, '..');
  for (const a of ASSETS) {
    const ctx = await browser.newContext({
      viewport: { width: a.source ? a.source.w : a.w, height: a.source ? a.source.h : a.h },
      deviceScaleFactor: a.scale,
    });
    const page = await ctx.newPage();
    await page.goto('file://' + path.resolve(__dirname, a.src));
    await page.waitForLoadState('networkidle');
    let buf = await page.screenshot({ omitBackground: false });
    // For the 128 icon, downscale from 256 source for sharper anti-aliased text.
    if (a.source) {
      const sharp = require('child_process').execSync;
      const tmp = path.join(outDir, '_tmp_' + a.out);
      require('fs').writeFileSync(tmp, buf);
      require('child_process').execSync(`sips -z ${a.h} ${a.w} "${tmp}" --out "${path.join(outDir, a.out)}" >/dev/null`);
      require('fs').unlinkSync(tmp);
    } else {
      require('fs').writeFileSync(path.join(outDir, a.out), buf);
    }
    console.log('✓', a.out);
    await ctx.close();
  }
  await browser.close();
})();
