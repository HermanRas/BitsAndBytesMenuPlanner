const { chromium } = require("playwright");

const pages = [
  "login",
  "today",
  "calendar",
  "week",
  "shopping-list",
  "settings",
  "meal-detail",
  "feedback",
  "feedback-review",
];

(async () => {
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });

  for (const name of pages) {
    const page = await context.newPage();
    await page.goto(`http://php-server:8000/${name}.html`, { waitUntil: "networkidle" });
    await page.screenshot({ path: `/screens/${name}.png`, fullPage: true });
    await page.close();
    console.log(`captured ${name}.png`);
  }

  await browser.close();
})();
