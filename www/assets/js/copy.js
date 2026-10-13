document.addEventListener("DOMContentLoaded", function () {
    // Codeblöcke bekommen oben rechts einen Copy-Button, Inline-Code nimmt den
    // Klick selbst entgegen, weil ein Button mitten im Satz die Zeile aufbricht.
    // Ohne Clipboard-API bleibt beides gewöhnlicher Text.
    if (!navigator.clipboard) return;

    function copy(text, done) {
        navigator.clipboard.writeText(text).then(done);
    }

    var blocks = document.querySelectorAll(".body pre");

    Array.prototype.forEach.call(blocks, function (block) {
        var text = block.innerText;

        var button = document.createElement("button");
        button.type = "button";
        button.className = "copy-button";
        button.textContent = "Copy";

        button.addEventListener("click", function () {
            copy(text, function () {
                button.textContent = "Copied";
                window.setTimeout(function () {
                    button.textContent = "Copy";
                }, 2000);
            });
        });

        block.classList.add("has-copy");
        block.appendChild(button);
    });

    var inline = document.querySelectorAll(".body code");

    Array.prototype.forEach.call(inline, function (code) {
        if (code.closest("pre")) return;

        code.classList.add("is-copyable");

        code.addEventListener("click", function () {
            var selection = window.getSelection();
            if (selection && selection.toString()) return;

            copy(code.textContent, function () {
                code.classList.add("is-copied");
                window.setTimeout(function () {
                    code.classList.remove("is-copied");
                }, 2000);
            });
        });
    });
});
