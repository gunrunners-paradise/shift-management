"use strict";

/**
 * Add small conveniences to server-rendered forms and native header dropdowns.
 * Authentication and account changes continue to use ordinary Laravel forms.
 */
document.addEventListener("DOMContentLoaded", initializePage);

function initializePage() {
    initializeDropdowns();
    initializePasswordVisibility();

    const errorSummary = document.querySelector("[data-error-summary]");

    // After a failed submission, put the Hebrew error list in the keyboard reading order.
    if (errorSummary) {
        errorSummary.focus();
    }
}

/** Keep one native details dropdown open, and return focus on Escape. */
function initializeDropdowns() {
    const dropdowns = document.querySelectorAll("[data-dropdown]");

    for (const dropdown of dropdowns) {
        const trigger = dropdown.querySelector("summary");
        trigger.setAttribute("aria-expanded", String(dropdown.open));

        dropdown.addEventListener("toggle", function () {
            trigger.setAttribute("aria-expanded", String(dropdown.open));

            if (dropdown.open) {
                closeOtherDropdowns(dropdowns, dropdown);
            }
        });
    }

    document.addEventListener("click", function (event) {
        for (const dropdown of dropdowns) {
            if (!dropdown.contains(event.target)) {
                dropdown.open = false;
            }
        }
    });

    document.addEventListener("focusin", function (event) {
        for (const dropdown of dropdowns) {
            if (!dropdown.contains(event.target)) {
                dropdown.open = false;
            }
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key !== "Escape") {
            return;
        }

        for (const dropdown of dropdowns) {
            if (dropdown.open) {
                dropdown.open = false;
                dropdown.querySelector("summary").focus();
            }
        }
    });
}

function closeOtherDropdowns(dropdowns, currentDropdown) {
    for (const dropdown of dropdowns) {
        if (dropdown !== currentDropdown) {
            dropdown.open = false;
        }
    }
}

/** Reveal buttons target their explicitly named input; no password is persisted. */
function initializePasswordVisibility() {
    const toggleButtons = document.querySelectorAll("[data-password-toggle]");

    for (const button of toggleButtons) {
        const input = document.getElementById(button.dataset.passwordToggle);

        if (!input) {
            continue;
        }

        const fieldLabel = button.getAttribute("aria-label").replace(/^הצגת /, "");
        button.hidden = false;

        button.addEventListener("click", function () {
            const shouldReveal = input.type === "password";
            input.type = shouldReveal ? "text" : "password";
            button.textContent = shouldReveal ? "הסתר" : "הצג";
            button.setAttribute("aria-pressed", String(shouldReveal));
            button.setAttribute("aria-label", (shouldReveal ? "הסתרת " : "הצגת ") + fieldLabel);
        });
    }
}
