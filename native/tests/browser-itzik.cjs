// Read-only chat regression against the demo; never confirms a shift.
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
    try {
        for (const role of ["manager", "worker"]) {
            const context = await browser.newContext();
            const page = await context.newPage();
            const errors = [];
            page.on("pageerror", (e) => errors.push(e.message));
            await page.goto(credentials.url);
            await page
                .locator("#login-form input[name=login]")
                .fill(credentials[role].login);
            await page
                .locator("#login-form input[name=password]")
                .fill(credentials[role].password);
            await page.locator("#login-form button").click();
            await page.locator("#calendar table").waitFor();
            // Exercise the actual failure: a Sunday in the displayed past.
            const israelDay = new Intl.DateTimeFormat("en-US", {
                timeZone: "Asia/Jerusalem",
                weekday: "long",
            }).format(new Date());
            if (role === "worker" && israelDay === "Sunday") {
                const response = page.waitForResponse(
                    (r) =>
                        r.url().includes("/api/week?start=") &&
                        r.status() === 200,
                );
                await page.locator("#previous-week").click();
                await response;
            }
            await page.locator("#itzik-toggle").click();
            async function say(text) {
                await page.locator("#itzik-text").fill(text);
                await page.locator("#itzik-form button").click();
            }
            await say("קבע לי ליום ראשון בבוקר");
            if (role === "manager") {
                await page
                    .locator("#itzik-messages")
                    .getByText("כרגע מחובר חשבון מנהל", { exact: false })
                    .waitFor();
                if (await page.locator("#itzik-messages button").count())
                    throw Error("Manager received booking confirmation");
            } else {
                await page
                    .locator("#itzik-messages")
                    .getByText("באילו שעות —", { exact: false })
                    .waitFor();
                const rejected = page.waitForResponse((r) =>
                    r.url().endsWith("/api/wolfe"),
                );
                await say("12-16");
                const rejection = await (await rejected).json();
                if (rejection.error !== "past_booking_date")
                    throw Error(JSON.stringify(rejection));
                await page
                    .locator("#itzik-messages")
                    .getByText("שמרתי את היום ואת השעות", { exact: false })
                    .waitFor();
                const accepted = page.waitForResponse((r) =>
                    r.url().endsWith("/api/wolfe"),
                );
                await say("בשבוע הבא");
                const proposal = await (await accepted).json();
                if (proposal.proposal?.period !== "12_16")
                    throw Error("Lost hours: " + JSON.stringify(proposal));
                console.log(
                    JSON.stringify({
                        rejection: rejection.message,
                        proposal: proposal.proposal,
                    }),
                );
                await page
                    .locator("#itzik-messages")
                    .getByRole("button", { name: "אישור הרשמה", exact: true })
                    .waitFor();
                await page.screenshot({
                    path: "/tmp/shift-itzik-hour-followup.png",
                    fullPage: true,
                });
            }
            if (errors.length) throw Error(errors.join("\n"));
            console.log(
                role +
                    ": screenshot phrase handled in chat" +
                    (role === "worker"
                        ? ", day → hours → past-date explanation → next week retained the request and offered confirmation."
                        : ", role explained."),
            );
            await page.locator("#logout").click();
            await page.locator("#login-screen").waitFor({ state: "visible" });
            await context.close();
        }
    } finally {
        await browser.close();
    }
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
