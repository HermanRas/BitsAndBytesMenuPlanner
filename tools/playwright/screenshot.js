const { chromium } = require("playwright");

const pagesAfterLogin = [
  "today.html",
  "calendar.html",
  "week.html",
  "shopping-list.html",
  "settings.php",
  "meal-detail.html",
  "feedback.html",
  "feedback-review.html",
];

(async () => {
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await context.newPage();

  await page.goto("http://php-server:8000/login.php", { waitUntil: "networkidle" });
  await page.screenshot({ path: "/screens/login.png", fullPage: true });

  await page.fill("#email", "herman.ras.it@gmail.com");
  await page.fill("#pin", "2233");
  await Promise.all([
    page.waitForURL("**/today.html"),
    page.click('button[value="login"]'),
  ]);
  console.log("captured login.png (logged in as Herman)");

  for (const file of pagesAfterLogin) {
    const name = file.replace(/\.(html|php)$/, "");
    await page.goto(`http://php-server:8000/${file}`, { waitUntil: "networkidle" });
    await page.screenshot({ path: `/screens/${name}.png`, fullPage: true });
    console.log(`captured ${name}.png`);
  }

  await browser.close();
})();
