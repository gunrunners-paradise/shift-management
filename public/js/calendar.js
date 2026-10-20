"use strict";

document.addEventListener("DOMContentLoaded", initializeShiftDialog);

/**
 * Enhance Blade's native POST selection forms with an office/none dialog.
 * Dates, period labels and DELETE form IDs come from the server-rendered DOM.
 * The page never rebuilds the calendar or stores authoritative state in JS.
 * Every mutation uses an existing CSRF-protected form and a full page reload.
 */
function initializeShiftDialog() {
    const dialog = document.getElementById("shift-dialog");

    // Without dialog support, the ordinary cell and clear forms keep working.
    if (!dialog || typeof dialog.showModal !== "function") {
        return;
    }

    const description = dialog.querySelector("[data-shift-description]");
    const officeButton = dialog.querySelector("[data-confirm-shift]");
    const clearButton = dialog.querySelector("[data-clear-shift]");
    const dismissButton = dialog.querySelector("[data-dismiss-shift]");
    let pendingForm = null;
    let returnFocusButton = null;

    for (const form of document.querySelectorAll("[data-shift-form]")) {
        form.addEventListener("submit", function (event) {
            const submitButton = form.querySelector("button[type='submit']");

            if (submitButton.disabled) {
                return;
            }

            event.preventDefault();
            pendingForm = form;
            returnFocusButton = event.submitter || submitButton;
            description.textContent = describeConfiguredPeriod(form) + " ביום " + form.dataset.dateLabel + ".";
            clearButton.disabled = !document.getElementById(form.dataset.clearForm);
            dialog.showModal();
            officeButton.focus();
        });
    }

    officeButton.addEventListener("click", function () {
        if (!pendingForm) {
            return;
        }

        const selectedForm = pendingForm;
        dialog.close();

        // Submit the original form without reopening its enhancement handler.
        // Laravel still validates role, ownership, date window and blocking.
        selectedForm.submit();
    });

    clearButton.addEventListener("click", function () {
        if (!pendingForm) {
            return;
        }

        const clearForm = document.getElementById(pendingForm.dataset.clearForm);
        dialog.close();

        if (clearForm) {
            clearForm.requestSubmit();
        }
    });

    dismissButton.addEventListener("click", function () {
        dialog.close();
    });

    dialog.addEventListener("click", function (event) {
        if (event.target !== dialog) {
            return;
        }

        const bounds = dialog.getBoundingClientRect();
        const clickedOutside = event.clientX < bounds.left || event.clientX > bounds.right
            || event.clientY < bounds.top || event.clientY > bounds.bottom;

        if (clickedOutside) {
            dialog.close();
        }
    });

    // The native dialog also dispatches close after Escape.
    dialog.addEventListener("close", function () {
        pendingForm = null;

        if (returnFocusButton && document.contains(returnFocusButton)) {
            returnFocusButton.focus();
        }

        returnFocusButton = null;
    });
}

/** Keep numeric time ranges in chronological visual order inside Hebrew text. */
function describeConfiguredPeriod(form) {
    const periodLabel = form.dataset.periodLabel;
    const periodTime = form.dataset.periodTime;
    // Unicode LTR isolation prevents the surrounding RTL sentence reversing the range.
    const isolatedTime = "\u2066" + periodTime + "\u2069";

    if (periodLabel.includes(periodTime)) {
        return periodLabel.replace(periodTime, isolatedTime);
    }

    return periodLabel + " (" + isolatedTime + ")";
}
