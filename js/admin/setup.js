document.addEventListener("DOMContentLoaded", function () {

    initBuildForm();
    initLogToggle();
    initLastBuildLog();

});

const buildUrl = "../admin/build/build.php";

function appendLogLine(log, obj) {
    const span = document.createElement("span");
    span.className = "log-" + (obj && obj.t ? obj.t : "info");
    span.textContent = obj ? obj.c : "";
    log.appendChild(span);
    log.scrollTop = log.scrollHeight;
}

function renderNdjsonText(log, text) {
    const lines = text.split("\n");
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        if (line === "") continue;
        try {
            appendLogLine(log, JSON.parse(line));
        } catch (e) {
            appendLogLine(log, { t: "out", c: line });
        }
    }
}

function initLastBuildLog() {
    const source = document.getElementById("cyphtwebmail-last-log");
    const log = document.getElementById("cyphtwebmail-log");
    if (!source || !log) return;

    let text;
    try {
        text = JSON.parse(source.textContent);
    } catch (e) {
        console.error("Could not parse embedded last-build-log JSON:", e);
        return;
    }

    if (!text) return;

    renderNdjsonText(log, text);
}

function setLogVisible(visible) {
    const wrap = document.getElementById("cyphtwebmail-log-wrap");
    const toggle = document.getElementById("cyphtwebmail-log-toggle");
    if (!wrap) return;

    wrap.style.display = visible ? "" : "none";

    if (toggle) {
        if (visible) {
            toggle.style.display = "";
        }
        toggle.textContent = visible
            ? (toggle.dataset.hideText || "Hide build log")
            : (toggle.dataset.showText || "Show build log");
    }
}

function initLogToggle() {
    const toggle = document.getElementById("cyphtwebmail-log-toggle");
    const wrap = document.getElementById("cyphtwebmail-log-wrap");

    if (!toggle || !wrap) return;

    toggle.addEventListener("click", function () {
        const currentlyVisible = wrap.style.display !== "none";
        setLogVisible(!currentlyVisible);
    });
}

function initBuildForm() {
    const form = document.getElementById("cypht-build-form");

    if (!form) return;

    form.addEventListener("submit", async function (e) {
        e.preventDefault();

        const button = form.querySelector('button[type="submit"]');
        if (!button) return;

        button.disabled = true;
        button.dataset.originalText = button.textContent;
        button.textContent = button.dataset.loadingText || 'Building...';
        setLogVisible(true);


        const formData = new FormData(form);

        try {
            const response = await fetch(buildUrl, {
                method: "POST",
                body: formData
            });

            // On error the body still explains why, so read it anyway.
            if (!response.ok) {
                console.log("Build response was not ok (status " + response.status + "), reading body anyway.");
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();

            const log = document.getElementById("cyphtwebmail-log");
            log.textContent = "";

            // A chunk can end mid-line, so buffer until a newline.
            let buffer = "";

            const processBuffer = function (flushRemainder) {
                let newlineIndex;
                while ((newlineIndex = buffer.indexOf("\n")) !== -1) {
                    const line = buffer.slice(0, newlineIndex);
                    buffer = buffer.slice(newlineIndex + 1);
                    if (line === "") continue;
                    try {
                        appendLogLine(log, JSON.parse(line));
                    } catch (e) {
                        appendLogLine(log, { t: "out", c: line });
                    }
                }
                if (flushRemainder && buffer !== "") {
                    try {
                        appendLogLine(log, JSON.parse(buffer));
                    } catch (e) {
                        appendLogLine(log, { t: "out", c: buffer });
                    }
                    buffer = "";
                }
            };

            while (true) {
                const { done, value } = await reader.read();

                if (done) {
                    processBuffer(true);
                    break;
                }

                buffer += decoder.decode(value, { stream: true });
                processBuffer(false);
            }
        } catch (err) {
            console.error(err);
        } finally {
            button.disabled = false;
            button.textContent = button.dataset.originalText || "Generate";
        }

    });
}

