"use strict";
const $ = (id) => document.getElementById(id);
const node = (tag, cls = "", text) => {
    const e = document.createElement(tag);
    e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
};
const S = {
    user: null,
    csrf: "",
    week: null,
    start: "",
    page: "week",
    epoch: 0,
    request: 0,
    chat: 0,
    pending: "",
};
const today = () => {
    const p = new Intl.DateTimeFormat("en-CA", {
        timeZone: "Asia/Jerusalem",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).formatToParts(new Date());
    return ["year", "month", "day"]
        .map((k) => p.find((x) => x.type === k).value)
        .join("-");
};
const add = (date, n) => {
    const d = new Date(date + "T12:00:00Z");
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
};
const sunday = (d) => add(d, -new Date(d + "T12:00:00Z").getUTCDay());
const label = (d) =>
    new Intl.DateTimeFormat("he-IL", {
        timeZone: "UTC",
        day: "numeric",
        month: "short",
        year: "numeric",
    }).format(new Date(d + "T12:00:00Z"));
const periodLabel = (p) =>
    p === "full"
        ? "יום מלא · 09:00–16:30"
        : {
              "09_13": "09:00–13:00",
              "10_14": "10:00–14:00",
              "11_15": "11:00–15:00",
              "12_16": "12:00–16:00",
          }[p] || p;
S.start = sunday(today());
let messageTimer;
function notify(text, error = false) {
    clearTimeout(messageTimer);
    $("message").textContent = text;
    $("message").classList.toggle("error", error);
    $("message").hidden = false;
    messageTimer = setTimeout(
        () => {
            $("message").hidden = true;
        },
        error ? 12000 : 6000,
    );
}
async function api(path, method = "GET", data) {
    const epoch = S.epoch;
    const headers = { Accept: "application/json" };
    if (method !== "GET") {
        headers["Content-Type"] = "application/json";
        headers["X-CSRF-Token"] = S.csrf;
    }
    const response = await fetch(path, {
        method,
        headers,
        credentials: "same-origin",
        cache: "no-store",
        ...(data === undefined ? {} : { body: JSON.stringify(data) }),
    });
    const value = (response.headers.get("Content-Type") || "").startsWith(
        "text/csv",
    )
        ? await response.text()
        : await response.json();
    if (epoch !== S.epoch) throw new Error("החיבור השתנה. נסו שוב.");
    if (!response.ok) {
        if (response.status === 401 && S.user) loggedOut();
        const error = new Error(value.message || "הפעולה לא הושלמה.");
        error.code = value.error;
        throw error;
    }
    if (value.csrf) S.csrf = value.csrf;
    return value;
}
async function action(fn, control) {
    if (control?.disabled) return;
    if (control) control.disabled = true;
    try {
        await fn();
    } catch (e) {
        notify(e.message, true);
    } finally {
        if (control) control.disabled = control.dataset.consumed === "true";
    }
}
function button(text, fn, cls = "button button--secondary button--small") {
    const b = node("button", cls, text);
    b.type = "button";
    b.addEventListener("click", () => action(fn, b));
    return b;
}
function bind(id, fn) {
    $(id).addEventListener("submit", (e) => {
        e.preventDefault();
        const form = e.currentTarget;
        if (form.dataset.busy) return;
        const values = Object.fromEntries(new FormData(form));
        form.dataset.busy = "1";
        const controls = [...form.elements].filter((x) => !x.disabled);
        controls.forEach((x) => (x.disabled = true));
        action(async () => {
            await fn(values, form);
        }).finally(() => {
            controls.forEach((x) => (x.disabled = false));
            delete form.dataset.busy;
        });
    });
}
function populate(form, data) {
    for (const [k, v] of Object.entries(data))
        if (form.elements.namedItem(k))
            form.elements.namedItem(k).value = v ?? "";
}
function matching(v, key = "password") {
    if (v[key] !== v.password_confirmation)
        throw new Error("הסיסמאות אינן זהות.");
    if (new TextEncoder().encode(v[key]).length > 128)
        throw new Error("הסיסמה ארוכה מדי (עד 128 בתים).");
}
function loggedOut() {
    S.epoch++;
    S.request++;
    S.chat++;
    S.user = null;
    S.csrf = "";
    S.pending = "";
    S.week = null;
    for (const id of [
        "calendar",
        "today-content",
        "desk-list",
        "admin-users-list",
        "admin-events-list",
        "admin-desks-list",
        "admin-closures-list",
        "admin-signups-list",
        "admin-receipts-list",
    ])
        $(id)?.replaceChildren();
    $("application").hidden = true;
    $("password-screen").hidden = true;
    $("account").hidden = true;
    $("itzik-toggle").hidden = true;
    $("itzik-chat").hidden = true;
    $("itzik-messages").replaceChildren();
    $("login-screen").hidden = false;
    document.querySelectorAll("form").forEach((f) => f.reset());
    $("edit-dialog").close();
}
async function accept(session) {
    S.user = session.user;
    S.csrf = session.csrf || "";
    if (!S.user) {
        loggedOut();
        return;
    }
    $("login-screen").hidden = true;
    $("account").hidden = false;
    $("account-name").textContent = S.user.name;
    const force = S.user.must_change_password;
    $("password-screen").hidden = !force;
    $("application").hidden = force;
    $("itzik-toggle").hidden = force;
    if (force) return;
    document
        .querySelectorAll("[data-admin]")
        .forEach((e) => (e.hidden = S.user.role !== "admin"));
    document
        .querySelectorAll("[data-worker]")
        .forEach((e) => (e.hidden = S.user.role === "admin"));
    await view("week");
}
async function view(name) {
    S.page = name;
    const workerWeek = name === "week" && S.user.role === "member";
    document
        .querySelectorAll("[data-page]")
        .forEach((e) => {
            // Reuse the receipt form and today's panels beneath the worker
            // calendar, in that order, as well as through their own shortcuts.
            e.hidden = e.id !== "view-" + name &&
                !(workerWeek && ["view-receipts", "view-today"].includes(e.id));
        });
    document
        .querySelectorAll("[data-view]")
        .forEach((e) =>
            e.setAttribute(
                "aria-current",
                e.dataset.view === name ? "page" : "false",
            ),
        );
    if (name === "week") await refreshWeek();
    if (name === "today" || workerWeek) {
        const v = await api("/api/today");
        const out = $("today-content");
        out.replaceChildren(node("h2", "", label(v.date)));
        for (const [title, rows] of [
            ["מי מגיע היום", v.coworkers],
            ["ימי הולדת 🎂", v.birthdays],
            ["אירועים", v.events],
        ]) {
            out.append(node("h3", "", title));
            list(
                out,
                rows,
                (x) =>
                    (x.name || x.title) +
                    (x.period ? " · " + periodLabel(x.period) : ""),
            );
        }
    }
    if (name === "profile") {
        const p = (await api("/api/profile")).users[0];
        populate($("profile-form"), p);
        $("profile-avatar").src = p.has_avatar
            ? "/api/avatar?id=" + p.id + "&v=" + Date.now()
            : "/images/default-avatar.svg";
    }
    if (name === "desks") {
        if (!$("desk-date").value) $("desk-date").value = today();
        await refreshDesks();
    }
    if (name === "receipts" || workerWeek) {
        const r = (await api("/api/receipts?month=" + today().slice(0, 7)))
            .receipts[0];
        populate($("receipt-form"), {
            has_receipts: String(Boolean(r?.has_receipts)),
        });
    }
    if (name === "admin")
        for (const e of document.querySelectorAll("[data-admin-panel][open]"))
            await adminPanel(e.dataset.adminPanel);
}
function list(container, rows, text, actions) {
    const l = node("div", "data-list");
    if (!rows.length) l.append(node("p", "empty", "אין רשומות להצגה"));
    for (const r of rows) {
        const row = node("div", "data-row");
        row.append(node("span", "", text(r)));
        if (actions) {
            const buttons = node("div", "row-actions");
            actions(r, buttons);
            row.append(buttons);
        }
        l.append(row);
    }
    container.append(l);
    return l;
}
async function refreshWeek() {
    const seq = ++S.request,
        start = S.start;
    const v = await api("/api/week?start=" + start);
    if (seq !== S.request) return;
    S.week = v;
    const admin = S.user.role === "admin";
    $("week-title").textContent = admin ? "לוח שבועי משותף" : "לוח המשמרות שלי";
    $("week-range").textContent = label(v.start) + " — " + label(v.end);
    $("week-help").textContent = admin
        ? "כל המשתתפים, לפי יום ושעות. סגירת יום מבטלת גם הזמנות של עמדות."
        : "בחרו שעות לכל יום. אפשר לשנות או לבטל עד סוף היום, עד ארבעה שבועות קדימה.";
    const table = node(
        "table",
        "shift-calendar" + (admin ? " manager-calendar" : ""),
    );
    const cols = node("colgroup");
    cols.append(node("col", "shift-label-column"));
    for (let i = 0; i < 5; i++) cols.append(node("col"));
    table.append(cols);
    const head = node("thead");
    const weekdays = node("tr", "calendar-weekdays");
    weekdays.append(node("th", "", "שעות"));
    const dates = node("tr", "calendar-dates");
    dates.append(node("th", "", "תאריך"));
    for (let i = 0; i < 5; i++) {
        weekdays.append(
            node("th", "", ["ראשון", "שני", "שלישי", "רביעי", "חמישי"][i]),
        );
        const date = add(start, i),
            th = node(
                "th",
                "calendar-date" +
                    (date === v.today ? " calendar-date--today" : ""),
            );
        th.append(node("time", "", label(date)));
        for (const ev of v.events.filter((e) => e.date === date))
            th.append(node("div", "calendar-date-event", ev.title));
        const closure = v.closures.find((c) => c.date === date);
        if (closure)
            th.append(
                node(
                    "div",
                    "calendar-date-event",
                    "סגור · " + (closure.reason || ""),
                ),
            );
        dates.append(th);
    }
    head.append(weekdays, dates);
    table.append(head);
    const body = node("tbody");
    for (const p of v.periods) {
        const row = node("tr");
        const heading = node("th", "shift-row-heading");
        if (p.code === "full")
            heading.append(node("span", "shift-full-label", "יום מלא"));
        const time = node("span", "shift-period-time", p.start + "–" + p.end);
        time.dir = "ltr";
        heading.append(time);
        row.append(heading);
        for (let i = 0; i < 5; i++) {
            const date = add(start, i),
                cell = node("td", "shift-cell"),
                records = v.shifts.filter(
                    (s) => s.date === date && s.period === p.code,
                ),
                closed = v.closures.some((c) => c.date === date),
                editable =
                    date >= v.today && date <= add(v.today, 28) && !closed;
            if (admin) {
                if (closed) cell.append(node("span", "", "סגור"));
                else if (records.length)
                    for (const r of records)
                        cell.append(node("span", "week-person", r.name));
                else cell.append(node("span", "empty", "—"));
            } else {
                const selected = records.length > 0,
                    has = v.shifts.some((s) => s.date === date);
                const b = button(
                    closed
                        ? "סגור"
                        : selected
                          ? "נרשמתי"
                          : has
                            ? "שינוי"
                            : "בחירה",
                    async () => {
                        if (
                            !confirm(
                                label(date) +
                                    " · " +
                                    periodLabel(p.code) +
                                    "\nלאשר את בחירת השעות?",
                            )
                        )
                            return;
                        await api("/api/shifts", "POST", {
                            date,
                            period: p.code,
                        });
                        notify("המשמרת נשמרה.");
                        await refreshWeek();
                    },
                    "shift-slot" +
                        (closed
                            ? " shift-slot--blocked"
                            : selected
                              ? " shift-slot--selected"
                              : has
                                ? " shift-slot--alternative"
                                : ""),
                );
                b.disabled = !editable;
                b.setAttribute(
                    "aria-label",
                    label(date) +
                        " " +
                        periodLabel(p.code) +
                        (selected ? " נרשמתי" : ""),
                );
                cell.append(b);
            }
            row.append(cell);
        }
        body.append(row);
    }
    table.append(body);
    const foot = node("tfoot"),
        fr = node("tr");
    fr.append(node("th", "", admin ? "ניהול יום" : "ביטול"));
    for (let i = 0; i < 5; i++) {
        const date = add(start, i),
            cell = node("td", "calendar-clear-cell"),
            closed = v.closures.some((c) => c.date === date);
        if (admin) {
            const b = button(
                closed ? "פתיחת יום" : "סגירת יום",
                () => changeClosure(date, closed),
                "calendar-clear-button",
            );
            b.disabled = date < v.today;
            cell.append(b);
        } else if (v.shifts.some((s) => s.date === date)) {
            const b = button(
                "ביטול הרשמה",
                async () => {
                    if (!confirm("לבטל את ההרשמה ליום " + label(date) + "?"))
                        return;
                    await api("/api/shifts", "DELETE", { date });
                    await refreshWeek();
                },
                "calendar-clear-button",
            );
            b.disabled = date < v.today;
            cell.append(b);
        }
        fr.append(cell);
    }
    foot.append(fr);
    table.append(foot);
    $("calendar").replaceChildren(table);
}
async function changeClosure(date, reopen, reason = "") {
    if (
        !confirm(
            reopen
                ? "לפתוח את היום? הרשמות שבוטלו לא ישוחזרו."
                : "לסגור את היום ולבטל את כל המשמרות והעמדות?",
        )
    )
        return;
    await api("/api/admin/closures", reopen ? "DELETE" : "POST", {
        date,
        reason,
        confirm: true,
    });
    notify(reopen ? "היום נפתח." : "היום נסגר.");
    if (S.page === "week") await refreshWeek();
    else await adminPanel("closures");
}
async function refreshDesks() {
    const date = $("desk-date").value;
    const v = await api("/api/desks?date=" + date);
    if (date !== $("desk-date").value) return;
    const out = $("desk-list");
    out.replaceChildren();
    const own = v.reservations.find((r) => r.mine);
    $("desk-select").replaceChildren(
        ...v.desks
            .filter((d) => d.active)
            .map((d) => {
                const o = node("option", "", d.name);
                o.value = d.id;
                return o;
            }),
    );
    const f = $("desk-form");
    f.reset();
    populate(f, own || { start_time: "09:00", end_time: "10:00" });
    for (const input of [f.elements.start_time, f.elements.end_time]) {
        input.min = "09:00";
        input.max = "16:30";
        input.step = 1800;
    }
    f.hidden = !v.editable;
    if (v.blocked) out.append(node("p", "", "המרכז סגור ביום הזה."));
    list(
        out,
        v.desks,
        (d) => d.name,
        (d, actions) => {
            const booked = v.reservations.filter((r) => r.desk_id === d.id);
            for (const r of booked) {
                actions.append(
                    node(
                        "span",
                        "",
                        `${r.start_time}–${r.end_time}${r.mine ? " · ההזמנה שלי" : ""}`,
                    ),
                );
                if (r.mine)
                    actions.append(
                        button("ביטול", async () => {
                            if (!confirm("לבטל את הזמנת העמדה?")) return;
                            await api("/api/desk-reservations", "DELETE", {
                                id: r.id,
                            });
                            await refreshDesks();
                        }),
                    );
            }
            if (!booked.length) actions.append(node("span", "", "פנוי"));
        },
    );
}
function dialog(title, fields, submit) {
    $("dialog-title").textContent = title;
    const f = node("form");
    for (const [name, label, type, value] of fields) {
        const l = node("label", "field", label),
            input = node("input", "form-control");
        input.name = name;
        input.type = type;
        input.value = value ?? "";
        input.required = true;
        if (type === "password") {
            input.minLength = 12;
            input.autocomplete = "new-password";
        }
        l.append(input);
        f.append(l);
    }
    const save = node("button", "button button--primary", "שמירה");
    f.append(save);
    f.addEventListener("submit", (e) => {
        e.preventDefault();
        action(async () => {
            await submit(Object.fromEntries(new FormData(f)));
            $("edit-dialog").close();
        }, save);
    });
    $("dialog-body").replaceChildren(f);
    $("edit-dialog").showModal();
}
async function adminPanel(name) {
    const out = $("admin-" + name + "-list");
    if (name === "report") {
        populate($("report-form"), {
            from: today().slice(0, 7) + "-01",
            to: today(),
            month: today().slice(0, 7),
        });
        return;
    }
    if (name === "receipts") {
        const form = $("admin-receipts-form");
        if (!form.elements.month.value)
            form.elements.month.value = today().slice(0, 7);
        const v = await api("/api/receipts?month=" + form.elements.month.value);
        out.replaceChildren();
        list(
            out,
            v.receipts,
            (r) => r.name + " · " + (r.has_receipts ? "יש קבלות" : "אין קבלות"),
        );
        return;
    }
    const v = await api("/api/admin/" + name);
    out.replaceChildren();
    if (name === "users")
        pagedList(
            out,
            v.users,
            (u) =>
                `${u.name} · ${u.login} · ${u.role === "admin" ? "מנהל/ת" : "משתתף/ת"}${u.active ? "" : " · לא פעיל"}`,
            (u, a) => {
                if (u.id !== S.user.id)
                    a.append(
                        button("איפוס סיסמה", () =>
                            dialog(
                                "איפוס סיסמה · " + u.name,
                                [
                                    ["password", "סיסמה חדשה", "password"],
                                    [
                                        "password_confirmation",
                                        "אימות סיסמה",
                                        "password",
                                    ],
                                ],
                                async (v) => {
                                    matching(v);
                                    await api(
                                        "/api/admin/accounts/reset-password",
                                        "POST",
                                        { ...v, id: u.id },
                                    );
                                    notify(
                                        "הסיסמה עודכנה והחיבורים הקודמים נסגרו.",
                                    );
                                },
                            ),
                        ),
                    );
                a.append(
                    button(u.active ? "השבתה" : "הפעלה", async () => {
                        if (
                            !confirm(
                                u.active
                                    ? "להשבית את החשבון ולבטל הרשמות עתידיות ופעילות?"
                                    : "להפעיל את החשבון? הרשמות שבוטלו לא ישוחזרו.",
                            )
                        )
                            return;
                        await api(
                            "/api/admin/accounts/" +
                                (u.active ? "deactivate" : "reactivate"),
                            "POST",
                            { id: u.id, confirm: true },
                        );
                        await adminPanel(name);
                    }),
                );
                if (!u.active)
                    a.append(
                        button(
                            "מחיקה לצמיתות",
                            async () => {
                                if (
                                    !confirm(
                                        "למחוק לצמיתות את " +
                                            u.name +
                                            " ואת כל היסטוריית ההרשמות והקבלות שלו?",
                                    )
                                )
                                    return;
                                await api(
                                    "/api/admin/accounts/delete",
                                    "POST",
                                    { id: u.id, confirm: true },
                                );
                                await adminPanel(name);
                            },
                            "button button--danger button--small",
                        ),
                    );
            },
        );
    if (name === "closures")
        list(
            out,
            v.closures,
            (c) => label(c.date) + " · " + (c.reason || ""),
            (c, a) =>
                a.append(
                    button("פתיחת יום", () => changeClosure(c.date, true)),
                ),
        );
    if (name === "events")
        list(
            out,
            v.events,
            (e) => label(e.date) + " · " + e.title,
            (e, a) => {
                a.append(button("עריכה", () => populate($("event-form"), e)));
                a.append(
                    button("מחיקה", async () => {
                        if (!confirm("למחוק את האירוע?")) return;
                        await api("/api/admin/events", "DELETE", { id: e.id });
                        await adminPanel(name);
                    }),
                );
            },
        );
    if (name === "desks")
        list(
            out,
            v.desks,
            (d) => d.name,
            (d, a) => {
                a.append(
                    button("שינוי שם", () => populate($("admin-desk-form"), d)),
                );
                a.append(
                    button("מחיקה", async () => {
                        if (
                            !confirm(
                                "למחוק את העמדה ואת כל היסטוריית ההזמנות שלה?",
                            )
                        )
                            return;
                        await api("/api/admin/desks", "DELETE", {
                            id: d.id,
                            confirm: true,
                        });
                        await adminPanel(name);
                    }),
                );
            },
        );
    if (name === "signups") {
        for (const setting of v.settings) {
            out.append(
                button(
                    `${setting.role === "admin" ? "מנהלים" : "משתתפים"}: ההרשמה ${setting.enabled ? "פתוחה — סגירה" : "סגורה — פתיחה"}`,
                    async () => {
                        await api("/api/admin/signup-settings", "POST", {
                            role: setting.role,
                            enabled: !setting.enabled,
                        });
                        await adminPanel(name);
                    },
                ),
            );
        }
        const review = async (action, id) => {
            const r = await api("/api/admin/signup-review", "POST", {
                action,
                ...(id ? { id } : {}),
            });
            if (
                !confirm(
                    `${action === "approve" ? "לאשר" : "למחוק"} ${r.workers} משתתפים ו-${r.managers} מנהלים?`,
                )
            )
                return;
            const result = await api("/api/admin/signup-process", "POST", {
                token: r.token,
            });
            notify(
                `טופלו ${result.processed} בקשות. התנגשויות: ${result.conflicts}`,
            );
            await adminPanel(name);
        };
        out.append(
            button("אישור כל הבקשות", () => review("approve")),
            button("מחיקת כל הבקשות", () => review("delete")),
        );
        let page = 0;
        const holder = node("div");
        out.append(holder);
        const render = () => {
            holder.replaceChildren();
            list(
                holder,
                v.requests.slice(page * 10, page * 10 + 10),
                (r) =>
                    `${r.first_name} ${r.last_name} · ${r.login} · ${r.role === "admin" ? "מנהל/ת" : "משתתף/ת"} ${r.review_error || ""}`,
                (r, a) =>
                    a.append(
                        button("אישור", () => review("approve", r.id)),
                        button("מחיקה", () => review("delete", r.id)),
                    ),
            );
            const nav = node("div", "row-actions");
            if (page > 0)
                nav.append(
                    button("הקודם", () => {
                        page--;
                        render();
                    }),
                );
            nav.append(
                node(
                    "span",
                    "",
                    `${page + 1} / ${Math.max(1, Math.ceil(v.requests.length / 10))}`,
                ),
            );
            if ((page + 1) * 10 < v.requests.length)
                nav.append(
                    button("הבא", () => {
                        page++;
                        render();
                    }),
                );
            holder.append(nav);
        };
        render();
    }
}
bind("login-form", async (v, f) => {
    v.login = v.login.trim().toLowerCase();
    const session = await api("/api/login", "POST", v);
    f.reset();
    S.epoch++;
    await accept(session);
});
for (const id of ["first-password-form", "password-form"])
    bind(id, async (v, f) => {
        matching(v, "new_password");
        await api("/api/password", "POST", v);
        f.reset();
        S.chat++;
        S.pending = "";
        await accept(await api("/api/session"));
        notify("הסיסמה עודכנה.");
    });
bind("profile-form", async (v) => {
    await api("/api/profile", "PATCH", v);
    const s = await api("/api/session");
    S.user = s.user;
    $("account-name").textContent = s.user.name;
    notify("הפרופיל נשמר.");
});
bind("desk-form", async (v) => {
    const id = Number(v.id);
    await api("/api/desk-reservations", id ? "PATCH" : "POST", {
        ...v,
        id,
        desk_id: Number(v.desk_id),
        date: $("desk-date").value,
    });
    await refreshDesks();
    notify("העמדה נשמרה.");
});
bind("receipt-form", async (v) => {
    await api("/api/receipts", "POST", {
        month: today().slice(0, 7),
        has_receipts: v.has_receipts === "true",
    });
    notify("הדיווח נשמר.");
});
bind("user-form", async (v, f) => {
    matching(v);
    v.login = v.login.trim().toLowerCase();
    await api("/api/admin/users", "POST", v);
    f.reset();
    await adminPanel("users");
    notify("החשבון נוסף.");
});
bind("closure-form", async (v) => changeClosure(v.date, false, v.reason));
bind("event-form", async (v, f) => {
    const id = Number(v.id);
    await api("/api/admin/events", id ? "PATCH" : "POST", { ...v, id });
    f.reset();
    await adminPanel("events");
});
bind("admin-desk-form", async (v, f) => {
    const id = Number(v.id);
    await api("/api/admin/desks", id ? "PATCH" : "POST", { ...v, id });
    f.reset();
    await adminPanel("desks");
});
bind("report-form", async (v) => {
    const csv = await api("/api/admin/report", "POST", v);
    const url = URL.createObjectURL(
        new Blob([csv], { type: "text/csv;charset=utf-8" }),
    );
    const a = node("a");
    a.href = url;
    a.download = `scheduled-workdays-${v.from}-${v.to}.csv`;
    document.body.append(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
});
$("logout").addEventListener("click", (e) =>
    action(async () => {
        await api("/api/logout", "POST", {});
        loggedOut();
    }, e.currentTarget),
);
document
    .querySelectorAll("[data-view]")
    .forEach((b) =>
        b.addEventListener("click", () =>
            action(() => view(b.dataset.view), b),
        ),
    );
document.querySelectorAll("[data-admin-panel]").forEach((d) =>
    d.addEventListener("toggle", () => {
        if (d.open && S.user?.role === "admin")
            action(() => adminPanel(d.dataset.adminPanel));
    }),
);
$("desk-date").addEventListener("change", () => action(refreshDesks));
for (const [id, n] of [
    ["previous-week", -7],
    ["next-week", 7],
    ["current-week", 0],
])
    $(id).addEventListener("click", () => {
        S.start = n ? add(S.start, n) : sunday(today());
        S.pending = "";
        S.chat++;
        action(refreshWeek);
    });
$("dialog-close").addEventListener("click", () => $("edit-dialog").close());
$("edit-dialog").addEventListener("close", () =>
    $("dialog-body").replaceChildren(),
);
function bubble(text, mine = false) {
    const b = node("div", "bubble" + (mine ? " mine" : ""), text);
    $("itzik-messages").append(b);
    $("itzik-messages").scrollTop = $("itzik-messages").scrollHeight;
    return b;
}
function openChat(open) {
    $("itzik-chat").hidden = !open;
    $("itzik-toggle").setAttribute("aria-expanded", String(open));
    if (open) {
        if (!$("itzik-messages").childElementCount)
            bubble(
                "אהלן! איציק כאן 🙂 באיזה יום ובאילו שעות נפגשים? אפשר גם לבקש את הלוח השבועי.",
            );
        $("itzik-text").focus();
    } else $("itzik-toggle").focus();
}
$("itzik-toggle").addEventListener("click", () =>
    openChat($("itzik-chat").hidden),
);
$("itzik-close").addEventListener("click", () => openChat(false));
$("itzik-chat").addEventListener("keydown", (e) => {
    if (e.key === "Escape") openChat(false);
});
bind("itzik-form", async (v, f) => {
    const ticket = ++S.chat,
        epoch = S.epoch;
    for (const b of $("itzik-messages").querySelectorAll("button"))
        b.disabled = true;
    bubble(v.text, true);
    const input = S.pending ? S.pending + " " + v.text : v.text;
    f.reset();
    let r;
    try {
        r = await api("/api/wolfe", "POST", {
            text: input,
            week_start: S.start,
        });
    } catch (error) {
        if (ticket === S.chat && epoch === S.epoch) {
            // A date correction continues the same request, including its hours.
            S.pending = error.code === "past_booking_date" ? input : "";
            bubble(error.message);
        }
        return;
    }
    if (ticket !== S.chat || epoch !== S.epoch) return;
    S.pending = r.status === "missing_arguments" ? input : "";
    const b = bubble(r.message);
    if (r.proposal) {
        const p = r.proposal;
        b.append(node("p", "", label(p.date) + " · " + periodLabel(p.period)));
        b.append(
            button("אישור הרשמה", async () => {
                if (ticket !== S.chat || epoch !== S.epoch) return;
                await api("/api/shifts", "POST", p);
                S.chat++;
                b.append(node("p", "", "נשמר! נתראה במרכז 🙂"));
                for (const x of b.querySelectorAll("button")) {
                    x.disabled = true;
                    x.dataset.consumed = "true";
                }
                S.start = sunday(p.date);
                await view("week");
            }),
        );
    }
    if (r.action === "show_schedule" || r.action === "show_my_bookings")
        await view("week");
});
async function init() {
    if (["/signup/worker", "/signup/manager"].includes(location.pathname)) {
        const endpoint = "/api" + location.pathname;
        $("signup-screen").hidden = false;
        const status = await api(endpoint);
        $("signup-message").textContent = status.enabled
            ? "מלאו את הפרטים. החשבון ייפתח לאחר אישור מנהל/ת."
            : "ההרשמה סגורה כעת.";
        $("signup-form").hidden = !status.enabled;
        bind("signup-form", async (v, f) => {
            matching(v);
            v.login = v.login.trim().toLowerCase();
            const result = await api(endpoint, "POST", v);
            f.reset();
            f.hidden = true;
            $("signup-message").textContent = result.message;
        });
        return;
    }
    await accept(await api("/api/session"));
}
init().catch((e) => {
    notify(e.message, true);
    if (!location.pathname.startsWith("/signup/")) loggedOut();
});

bind("avatar-form", async (v, f) => {
    const file = f.elements.photo.files[0];
    if (!file || file.size > 2097152) throw new Error("בחרו תמונה עד 2MB.");
    const base64 = await new Promise((resolve, reject) => {
        const r = new FileReader();
        r.onload = () => resolve(r.result.split(",")[1]);
        r.onerror = () => reject(new Error("לא ניתן לקרוא את התמונה."));
        r.readAsDataURL(file);
    });
    await api("/api/avatar", "POST", { base64 });
    f.reset();
    await view("profile");
    notify("התמונה עודכנה.");
});
$("avatar-delete").addEventListener("click", (e) =>
    action(async () => {
        await api("/api/avatar", "DELETE", {});
        await view("profile");
    }, e.currentTarget),
);

function pagedList(out, rows, text, actions) {
    let page = 0;
    const holder = node("div");
    out.append(holder);
    const render = () => {
        holder.replaceChildren();
        list(holder, rows.slice(page * 10, page * 10 + 10), text, actions);
        const nav = node("div", "row-actions");
        if (page > 0)
            nav.append(
                button("הקודם", () => {
                    page--;
                    render();
                }),
            );
        nav.append(
            node(
                "span",
                "",
                `${page + 1} / ${Math.max(1, Math.ceil(rows.length / 10))}`,
            ),
        );
        if ((page + 1) * 10 < rows.length)
            nav.append(
                button("הבא", () => {
                    page++;
                    render();
                }),
            );
        holder.append(nav);
    };
    render();
}
bind("admin-receipts-form", () => adminPanel("receipts"));
