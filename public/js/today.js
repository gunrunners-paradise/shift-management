"use strict";

document.addEventListener("DOMContentLoaded", initializeTodayInformation);

/**
 * Refresh only the announcement, birthday and coworker panels below the Blade calendar.
 * GET /worker/today returns date, display_date, birthdays[name, avatar_url],
 * coworkers[name, avatar_url, period_label, start, end], and
 * announcements[title, description, kind] for actual today.
 * Initial Blade content works without JS. Polling pauses in hidden tabs,
 * resumes immediately on visibility/focus, and never rebuilds calendar cells.
 */
function initializeTodayInformation() {
    const container = document.querySelector("[data-today-information]");

    if (!container) {
        return;
    }

    const refreshDelay = 30_000;
    const status = container.querySelector("[data-today-refresh-status]");
    let refreshTimer = null;
    let requestController = null;
    let isRefreshing = false;
    let pollingStopped = false;
    let previousSnapshot = null;

    function cancelScheduledRefresh() {
        if (refreshTimer !== null) {
            window.clearTimeout(refreshTimer);
            refreshTimer = null;
        }
    }

    function scheduleNextRefresh() {
        cancelScheduledRefresh();

        if (!document.hidden && !pollingStopped) {
            refreshTimer = window.setTimeout(refreshTodayInformation, refreshDelay);
        }
    }

    async function refreshTodayInformation() {
        if (document.hidden || pollingStopped || isRefreshing) {
            return;
        }

        cancelScheduledRefresh();
        isRefreshing = true;
        requestController = new AbortController();
        const requestTimeout = window.setTimeout(function () {
            requestController.abort();
        }, 10_000);

        try {
            const response = await fetch(container.dataset.refreshUrl, {
                credentials: "same-origin",
                cache: "no-store",
                redirect: "follow",
                headers: { Accept: "application/json" },
                signal: requestController.signal,
            });

            if (response.redirected || response.status === 401 || response.status === 403) {
                pollingStopped = true;
                clearTodayInformation(container);
                status.textContent = "החיבור למערכת הסתיים. יש לרענן את העמוד ולהתחבר שוב.";
                status.hidden = false;
                return;
            }

            if (!response.ok) {
                throw new Error("Today information could not be refreshed.");
            }

            const information = await response.json();

            if (!Array.isArray(information.birthdays) || !Array.isArray(information.coworkers)
                || !Array.isArray(information.announcements)) {
                throw new Error("Today information has an unexpected format.");
            }

            // A request already in progress may finish after the tab is hidden.
            // Keep the displayed data until the next visible refresh in that case.
            if (document.hidden) {
                return;
            }

            const snapshot = JSON.stringify(information);

            if (snapshot !== previousSnapshot) {
                renderTodayInformation(container, information);
                previousSnapshot = snapshot;
            }

            status.hidden = true;
            status.textContent = "";
        } catch (error) {
            if (!pollingStopped) {
                status.textContent = "לא ניתן לעדכן כרגע. העדכון הבא ינסה שוב.";
                status.hidden = false;
            }
        } finally {
            window.clearTimeout(requestTimeout);
            requestController = null;
            isRefreshing = false;
            scheduleNextRefresh();
        }
    }

    document.addEventListener("visibilitychange", function () {
        cancelScheduledRefresh();

        if (!document.hidden) {
            refreshTodayInformation();
        }
    });

    window.addEventListener("focus", refreshTodayInformation);
    window.addEventListener("pagehide", function () {
        pollingStopped = true;
        cancelScheduledRefresh();

        if (requestController) {
            requestController.abort();
        }
    });
    window.addEventListener("pageshow", function (event) {
        if (event.persisted) {
            pollingStopped = false;
            refreshTodayInformation();
        }
    });

    scheduleNextRefresh();
}

/** Update trusted server values through text nodes and attributes, never HTML strings. */
function renderTodayInformation(container, information) {
    renderTodayAnnouncements(container, information.announcements);

    const birthdaySection = container.querySelector("#birthdays");
    const birthdayList = container.querySelector("[data-birthday-list]");
    const birthdayPeople = document.createDocumentFragment();

    for (const person of information.birthdays) {
        const card = document.createElement("li");
        card.className = "today-birthday-person";
        const details = createTodayPersonDetails(person.name);
        const greeting = document.createElement("span");
        greeting.textContent = "מזל טוב ליום ההולדת!";
        details.append(greeting);
        card.append(createTodayAvatar(person.avatar_url, 44), details);
        birthdayPeople.append(card);
    }

    birthdayList.replaceChildren(birthdayPeople);
    birthdaySection.hidden = information.birthdays.length === 0;

    const coworkerList = container.querySelector("[data-coworkers-list]");
    const coworkers = document.createDocumentFragment();

    for (const person of information.coworkers) {
        const card = document.createElement("li");
        card.className = "today-coworker";
        const details = createTodayPersonDetails(person.name);
        const period = document.createElement("span");
        period.className = "today-person-period";
        period.title = person.period_label;

        const timeRange = person.start + "–" + person.end;
        const periodTitle = document.createElement("span");
        periodTitle.textContent = person.period_label.replace(timeRange, "").trim();
        const periodTime = document.createElement("bdi");
        periodTime.dir = "ltr";
        periodTime.textContent = timeRange;
        period.append(periodTitle, periodTime);
        details.append(period);
        card.append(createTodayAvatar(person.avatar_url, 46), details);
        coworkers.append(card);
    }

    if (information.coworkers.length === 0) {
        const emptyMessage = document.createElement("li");
        emptyMessage.className = "today-coworkers-empty";
        emptyMessage.textContent = "אין עובדים רשומים למשמרת היום.";
        coworkers.append(emptyMessage);
    }

    coworkerList.replaceChildren(coworkers);
    const coworkerCount = container.querySelector("[data-coworkers-count]");
    coworkerCount.textContent = information.coworkers.length + " עובדים";
    coworkerCount.hidden = false;
    for (const date of container.querySelectorAll("[data-today-date]")) {
        date.dateTime = information.date;
        date.textContent = information.display_date;
    }
}

/** Announcements are informational text; their appearance never changes grid availability. */
function renderTodayAnnouncements(container, announcements) {
    const section = container.querySelector("#announcements");
    const list = container.querySelector("[data-announcements-list]");
    const notices = document.createDocumentFragment();

    for (const announcement of announcements) {
        const item = document.createElement("li");
        item.className = "today-announcement";
        const heading = document.createElement("div");
        heading.className = "today-announcement-heading";
        const title = document.createElement("h3");
        title.textContent = announcement.title;
        const kind = document.createElement("span");
        kind.className = "today-announcement-kind";
        kind.textContent = announcement.kind === "holiday" ? "חג" : "אירוע";
        heading.append(title, kind);
        item.append(heading);

        if (announcement.description) {
            const description = document.createElement("p");
            description.textContent = announcement.description;
            item.append(description);
        }

        notices.append(item);
    }

    list.replaceChildren(notices);
    section.hidden = announcements.length === 0;
}

function createTodayPersonDetails(name) {
    const details = document.createElement("div");
    details.className = "today-person-details";
    const heading = document.createElement("strong");
    heading.textContent = name;
    details.append(heading);
    return details;
}

function createTodayAvatar(url, size) {
    const avatar = document.createElement("img");
    avatar.className = "today-person-avatar";
    avatar.src = url;
    avatar.alt = "";
    avatar.width = size;
    avatar.height = size;
    avatar.loading = "lazy";
    return avatar;
}

/** Remove refreshed information once the endpoint no longer authorizes this browser. */
function clearTodayInformation(container) {
    container.querySelector("#announcements").hidden = true;
    container.querySelector("[data-announcements-list]").replaceChildren();
    container.querySelector("#birthdays").hidden = true;
    container.querySelector("[data-birthday-list]").replaceChildren();
    container.querySelector("[data-coworkers-list]").replaceChildren();
    container.querySelector("[data-coworkers-count]").hidden = true;
}
