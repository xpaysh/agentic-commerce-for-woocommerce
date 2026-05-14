const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const SCREENS = [
  { src: 'screen-1.html', out: 'screenshot-1.png', w: 1600, h: 1000 },
  { src: 'screen-2.html', out: 'screenshot-2.png', w: 1600, h: 1000 },
  { src: 'screen-3.html', out: 'screenshot-3.png', w: 1600, h: 1000 },
  { src: 'screen-4.html', out: 'screenshot-4.png', w: 1600, h: 1000 },
  { src: 'screen-5.html', out: 'screenshot-5.png', w: 1600, h: 1000 },
];

(async () => {
  const browser = await chromium.launch();
  const outDir = path.resolve(__dirname, '..');
  for (const s of SCREENS) {
    const ctx = await browser.newContext({
      viewport: { width: s.w, height: s.h },
      deviceScaleFactor: 2, // retina sharp
    });
    const page = await ctx.newPage();
    const url = 'file://' + path.resolve(__dirname, s.src);
    await page.goto(url);
    await page.waitForLoadState('networkidle');
    const outPath = path.join(outDir, s.out);
    await page.screenshot({ path: outPath, fullPage: false });
    console.log('✓', s.out, '←', s.src);
    await ctx.close();
  }
  await browser.close();
})();
