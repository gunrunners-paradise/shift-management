// Run against a private demo credential file; never against learner data.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || "playwright");
const fs = require("node:fs");
(async () => {
    const credentials = JSON.parse(
        fs.readFileSync(process.env.DEMO_CREDENTIALS, "utf8"),
    );
    const browser = await chromium.launch({
        headless: true,
        args: ["--no-sandbox"],
    });
    const context = await browser.newContext({
        viewport: { width: 1440, height: 1000 },
    });
    const page = await context.newPage();
    const errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    async function login(c) {
        await page.goto(credentials.url);
        await page.locator("#login-form input[name=login]").fill(c.login);
        await page.locator("#login-form input[name=password]").fill(c.password);
        await page.locator("#login-form button").click();
        await page.locator("#calendar table").waitFor();
    }
    async function view(v) {
        await page.locator(`#navigation [data-view=${v}]`).click();
        await page.locator(`#view-${v}`).waitFor({ state: "visible" });
    }
    await login(credentials.manager);
    await page.locator(".week-person").first().waitFor();
    if ((await page.locator("thead .calendar-weekdays th").count()) !== 6)
        throw Error("Missing five-day manager week");
    await page.screenshot({
        path: "/tmp/shift-manager-desktop.png",
        fullPage: true,
    });
    await page.locator("#itzik-toggle").click();
    await page.locator("#itzik-text").fill("תראה לי את השבוע");
    await page.locator("#itzik-form button").click();
    await page.getByText("הנה לוח השבוע של כולם.", { exact: true }).waitFor();
    await page.screenshot({
        path: "/tmp/shift-manager-itzik.png",
        fullPage: true,
    });
    await page.locator("#itzik-close").click();
    await view("admin");
    for (const name of [
        "users",
        "closures",
        "events",
        "desks",
        "signups",
        "receipts",
        "report",
    ]) {
        await page.locator(`[data-admin-panel=${name}] > summary`).click();
        await page.waitForTimeout(150);
    }
    await page.locator("#admin-users-list .data-row").first().waitFor();
    await page.locator("[data-admin-panel=users] details > summary").click();
    const newLogin = "browser-" + Date.now();
    const newPassword = require("node:crypto").randomBytes(18).toString("hex");
    for (const [field, value] of Object.entries({
        login: newLogin,
        first_name: "בדיקת",
        last_name: "דפדפן",
        password: newPassword,
        password_confirmation: newPassword,
    }))
        await page.locator(`#user-form [name=${field}]`).fill(value);
    await page.locator("#user-form button").click();
    const created = page
        .locator("#admin-users-list .data-row")
        .filter({ hasText: newLogin });
    await created.waitFor();
    page.once("dialog", (d) => d.accept());
    await created.getByRole("button", { name: "השבתה", exact: true }).click();
    await created.getByText("לא פעיל", { exact: false }).waitFor();
    page.once("dialog", (d) => d.accept());
    await created
        .getByRole("button", { name: "מחיקה לצמיתות", exact: true })
        .click();
    await created.waitFor({ state: "detached" });
    await view("profile");
    await page.locator("#profile-form input[name=first_name]").waitFor();
    await view("week");
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({
        path: "/tmp/shift-manager-mobile.png",
        fullPage: true,
    });
    if (
        await page.evaluate(
            () => document.documentElement.scrollWidth > innerWidth + 2,
        )
    )
        throw Error("Page overflow outside calendar");
    await page.locator("#logout").click();
    await page.locator("#login-screen").waitFor({ state: "visible" });
    await login(credentials.worker);
    if (await page.locator("#navigation [data-view=admin]").isVisible())
        throw Error("Worker admin navigation visible");
    await page.screenshot({
        path: "/tmp/shift-worker-mobile.png",
        fullPage: true,
    });
    await view("desks");
    await page.locator("#desk-list .data-row").first().waitFor();
    await view("today");
    await page.locator("#today-content h2").waitFor();
    await view("receipts");
    await view("profile");
    if (errors.length) throw Error(errors.join("\n"));
    await browser.close();
    console.log(
        "Browser: manager week, Itzik, seven admin panels, worker navigation, profile, desks, receipts; desktop and mobile passed.",
    );
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
