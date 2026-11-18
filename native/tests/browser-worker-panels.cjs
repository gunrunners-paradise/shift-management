// Run only against disposable test data. Uses the same private credential
// format as browser.cjs and does not add a browser dependency to the app.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || "playwright");
const fs = require("node:fs");
const assert = require("node:assert/strict");

(async () => {
    const credentials = JSON.parse(fs.readFileSync(process.env.DEMO_CREDENTIALS, "utf8"));
    const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on("pageerror", error => errors.push(error.message));
        async function login(account) {
            await page.goto(credentials.url);
            await page.locator("#login-form [name=login]").fill(account.login);
            await page.locator("#login-form [name=password]").fill(account.password);
            await page.locator("#login-form button").click();
            await page.locator("#calendar table").waitFor();
        }
        await login(credentials.worker);
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.locator("#today-content h2").waitFor();
            assert(await page.locator("#receipt-form").isVisible());
            const boxes = await Promise.all(["calendar", "view-receipts", "view-today"]
                .map(id => page.locator("#" + id).boundingBox()));
            assert(boxes[1].y >= boxes[0].y + boxes[0].height, "Receipts follow the calendar");
            assert(boxes[2].y >= boxes[1].y + boxes[1].height, "Today follows receipts");
            assert.equal(await page.locator("#today-content h3").count(), 3);
            assert.equal(await page.locator("#receipt-form [name=has_receipts]").inputValue(), "true");
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 2), false);
            await page.screenshot({ path: `/tmp/shift-worker-panels-${width}.png`, fullPage: true });
        }
        const date = await page.locator("#today-content h2").textContent();
        await Promise.all([
            page.waitForResponse(response => response.url().includes("/api/week?")),
            page.locator("#next-week").click(),
        ]);
        assert.equal(await page.locator("#today-content h2").textContent(), date);
        await page.locator("#receipt-form [name=has_receipts]").selectOption("false");
        await Promise.all([
            page.waitForResponse(response => response.url().endsWith("/api/receipts") && response.request().method() === "POST"),
            page.locator("#receipt-form button").click(),
        ]);
        await page.reload();
        await page.locator("#today-content h2").waitFor();
        await page.waitForFunction(() => document.querySelector("#receipt-form [name=has_receipts]").value === "false");
        await page.locator("#navigation [data-view=profile]").click();
        assert.equal(await page.locator("#receipt-form").isVisible(), false);
        assert.equal(await page.locator("#today-content").isVisible(), false);
        await page.locator("#logout").click();
        await page.locator("#login-screen").waitFor();
        await login(credentials.manager);
        assert.equal(await page.locator("#receipt-form").isVisible(), false);
        assert.equal(await page.locator("#today-content").isVisible(), false);
        assert.deepEqual(errors, []);
        console.log("Worker calendar: receipts then today's three panels at 1440/390px; saved receipt survives reload; week navigation keeps today's date; manager has no worker panels.");
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
