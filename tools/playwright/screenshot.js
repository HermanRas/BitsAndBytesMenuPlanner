const { chromium } = require("playwright");

const pagesAfterLogin = [
  ["today", "today.php"],
  ["calendar", "calendar.php"],
  ["week", "week.php"],
  ["shopping-list", "shopping-list.html"],
  ["settings", "settings.php"],
  ["meal-detail", "meal-detail.php?entry=8"],
  ["meals", "meals.php"],
  ["meal-form", "meal-form.php"],
  ["ingredients", "ingredients.php"],
  ["feedback", "feedback.html"],
  ["feedback-review", "feedback-review.html"],
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
    page.waitForURL("**/today.php"),
    page.click('button[value="login"]'),
  ]);
  console.log("captured login.png (logged in as Herman)");

  for (const [name, file] of pagesAfterLogin) {
    await page.goto(`http://php-server:8000/${file}`, { waitUntil: "networkidle" });
    await page.screenshot({ path: `/screens/${name}.png`, fullPage: true });
    console.log(`captured ${name}.png`);
  }

  await browser.close();
})();
